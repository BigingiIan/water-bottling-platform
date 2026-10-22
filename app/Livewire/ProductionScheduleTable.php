<?php

namespace App\Livewire;

use App\Models\ProductionLine;
use App\Models\ProductionSchedule;
use App\Models\User;
use App\Services\MLForecastClient;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

class ProductionScheduleTable extends Component
{
    public string $selectedDate = '';

    public string $lineFilter = '';

    public string $skuFilter = '';

    public string $statusFilter = '';

    public function mount(): void
    {
        $requestedDate = request()->query('date');
        $requestedSku = request()->query('sku');

        $this->selectedDate = $this->resolveDate(is_string($requestedDate) ? $requestedDate : null);
        $this->skuFilter = is_string($requestedSku) && mb_strlen($requestedSku) <= 255 ? $requestedSku : '';
    }

    public function updatedSelectedDate(): void
    {
        $this->selectedDate = $this->resolveDate($this->selectedDate);
    }

    #[On('production-logged')]
    #[On('schedule-created')]
    public function refreshTotals(): void
    {
        // No-op: receiving the event re-renders the table with fresh schedules and log totals.
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production');

        $schedules = ProductionSchedule::query()
            ->with('productionLine')
            ->withSum('logs', 'units_logged')
            ->whereDate('scheduled_date', $this->selectedDate)
            ->when($this->lineFilter !== '', function (Builder $query): void {
                $query->where('production_line_id', (int) $this->lineFilter);
            })
            ->when($this->skuFilter !== '', function (Builder $query): void {
                $query->where('product_sku', $this->skuFilter);
            })
            ->when(in_array($this->statusFilter, ['queued', 'active', 'complete'], true), function (Builder $query): void {
                $query->where('status', $this->statusFilter);
            })
            ->orderBy('scheduled_time')
            ->get();

        $daySchedules = ProductionSchedule::query()
            ->with('productionLine')
            ->withSum('logs', 'units_logged')
            ->whereDate('scheduled_date', $this->selectedDate)
            ->orderBy('scheduled_time')
            ->get();

        $dayTarget = (int) $daySchedules->sum('target_units');
        $dayActual = (int) $daySchedules->sum('logs_sum_units_logged');
        $totalJobs = $daySchedules->count();
        $completedJobs = $daySchedules->where('status', 'complete')->count();
        $activeLineIds = $daySchedules->where('status', 'active')->pluck('production_line_id')->unique()->values();
        $totalLines = ProductionLine::query()->count();
        $forecastComparison = $this->forecastComparison();

        return view('livewire.production-schedule-table', [
            'schedules' => $schedules,
            'forecastComparison' => $forecastComparison,
            'lines' => ProductionLine::query()->orderBy('name')->get(),
            'skus' => ProductionSchedule::query()
                ->whereDate('scheduled_date', $this->selectedDate)
                ->distinct()
                ->orderBy('product_sku')
                ->pluck('product_sku'),
            'statusPresentation' => [
                'complete' => ['label' => 'Complete', 'class' => 'bg-green-50 text-[#15803d]', 'dotClass' => 'bg-green-500', 'barClass' => 'bg-green-500'],
                'active' => ['label' => 'Active', 'class' => 'bg-blue-50 text-[#1d4ed8]', 'dotClass' => 'bg-blue-500', 'barClass' => 'bg-blue-500'],
                'queued' => ['label' => 'Queued', 'class' => 'bg-gray-100 text-gray-600', 'dotClass' => 'bg-gray-400', 'barClass' => 'bg-blue-500'],
            ],
            'totalTarget' => $schedules->sum('target_units'),
            'totalActual' => $schedules->sum('logs_sum_units_logged'),
            'kpis' => [
                'output' => $dayActual,
                'outputTarget' => $dayTarget,
                'outputPct' => $dayTarget > 0 ? min(100, (int) round($dayActual / $dayTarget * 100)) : 0,
                'completed' => $completedJobs,
                'totalJobs' => $totalJobs,
                'jobsPct' => $totalJobs > 0 ? min(100, (int) round($completedJobs / $totalJobs * 100)) : 0,
                'activeLines' => $activeLineIds->count(),
                'totalLines' => $totalLines,
                'activeLineNames' => ProductionLine::query()->whereIn('id', $activeLineIds)->orderBy('name')->pluck('name'),
            ],
            'canLog' => $this->canLog($user),
            'activeRun' => $this->activeRun($schedules),
            'alerts' => $this->lineAlerts($schedules, $forecastComparison),
        ]);
    }

    private function resolveDate(?string $value): string
    {
        if ($value !== null && $value !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                // Fall through to today.
            }
        }

        return today()->toDateString();
    }

    /**
     * Whether the current user may create production logs (production module, full access).
     */
    private function canLog(User $user): bool
    {
        $accessLevel = DB::table('role_module_permissions')
            ->join('modules', 'modules.id', '=', 'role_module_permissions.module_id')
            ->where('role_module_permissions.role_id', $user->role_id)
            ->where('modules.slug', 'production')
            ->value('role_module_permissions.access_level');

        return $accessLevel === 'full';
    }

    /**
     * The run shown in the detail panel: earliest active schedule, with operator info.
     *
     * @param  Collection<int, ProductionSchedule>  $daySchedules
     * @return array{sku:string,line:string,operator:string,started:string,status:string,actual:int,target:int,progress:int,scheduleId:int}|null
     */
    private function activeRun($daySchedules): ?array
    {
        $run = $daySchedules->firstWhere('status', 'active');

        if (! $run instanceof ProductionSchedule) {
            return null;
        }

        $latestLog = $run->logs()->with('operator')->latest('logged_at')->first();
        $actual = (int) ($run->logs_sum_units_logged ?? $run->logs()->sum('units_logged'));
        $target = (int) $run->target_units;

        return [
            'sku' => $run->product_sku,
            'line' => $run->productionLine?->name ?? '—',
            'operator' => $latestLog?->operator?->name ?? '—',
            'started' => mb_substr((string) $run->scheduled_time, 0, 5),
            'status' => $run->status,
            'actual' => $actual,
            'target' => $target,
            'progress' => $target > 0 ? min(100, (int) round($actual / $target * 100)) : 0,
            'scheduleId' => $run->id,
        ];
    }

    /**
     * Derive line alerts from real schedule/log state instead of mock copy.
     *
     * @param  Collection<int, ProductionSchedule>  $daySchedules
     * @return list<array{color:string,html:string,time:string}>
     */
    private function lineAlerts($daySchedules, ?array $forecastComparison): array
    {
        $alerts = [];

        foreach ($daySchedules->where('status', 'active') as $run) {
            $actual = (int) ($run->logs_sum_units_logged ?? 0);
            $target = (int) $run->target_units;

            if ($target > 0 && $actual < $target) {
                $pct = (int) round(($target - $actual) / $target * 100);
                if ($pct >= 5) {
                    $alerts[] = [
                        'color' => 'bg-amber-500',
                        'html' => '<b>'.e($run->productionLine?->name ?? 'Line').'</b> running '.$pct.'% below target pace',
                        'time' => $run->updated_at?->diffForHumans() ?? 'recently',
                    ];
                    break;
                }
            }
        }

        if (
            $forecastComparison !== null
            && ($forecastComparison['available'] ?? false)
            && ($forecastComparison['isBelow'] ?? false)
        ) {
            $alerts[] = [
                'color' => 'bg-blue-500',
                'html' => 'Forecast suggests increasing '.e($forecastComparison['sku']).' target by '.number_format($forecastComparison['shortfall']).' units',
                'time' => 'live forecast',
            ];
        }

        $completed = $daySchedules->where('status', 'complete')->sortByDesc('updated_at')->first();
        if ($completed instanceof ProductionSchedule) {
            $alerts[] = [
                'color' => 'bg-green-500',
                'html' => '<b>'.e($completed->productionLine?->name ?? 'Line').'</b> completed '.e($completed->product_sku).' run on target',
                'time' => $completed->updated_at?->diffForHumans() ?? 'recently',
            ];
        }

        return array_slice($alerts, 0, 3);
    }

    /**
     * Compare the ML prediction for the next active SKU with its scheduled target.
     *
     * The ML client returns only a nullable float, so when it returns null the
     * distinction between "insufficient sales history" and "model not yet
     * trained / service unavailable" is not available — callers must show a
     * generic "forecast unavailable" message and never a placeholder number.
     *
     * @return array{available:false,sku:string,target:int,reason:string}|array{available:true,sku:string,demand:int,target:int,shortfall:int,shortfallPct:float,isBelow:bool}|null
     */
    private function forecastComparison(): ?array
    {
        $active = ProductionSchedule::query()
            ->whereDate('scheduled_date', $this->selectedDate)
            ->where('status', 'active')
            ->orderBy('scheduled_time')
            ->first();

        if ($active === null) {
            return null;
        }

        $target = (int) ProductionSchedule::query()
            ->whereDate('scheduled_date', $this->selectedDate)
            ->where('product_sku', $active->product_sku)
            ->sum('target_units');

        $prediction = app(MLForecastClient::class)->predict($active->product_sku, $this->selectedDate);

        if ($prediction === null) {
            return [
                'available' => false,
                'sku' => $active->product_sku,
                'target' => $target,
                'reason' => 'unavailable',
            ];
        }

        $demand = (int) round($prediction);
        $shortfall = $demand - $target;
        $shortfallPct = $demand > 0 ? $shortfall / $demand * 100 : 0;

        return [
            'available' => true,
            'sku' => $active->product_sku,
            'demand' => $demand,
            'target' => $target,
            'shortfall' => $shortfall,
            'shortfallPct' => $shortfallPct,
            'isBelow' => $shortfallPct > 5,
        ];
    }
}
