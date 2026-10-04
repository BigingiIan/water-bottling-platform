<?php

namespace App\Livewire;

use App\Models\ProductionLine;
use App\Models\ProductionSchedule;
use App\Models\User;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Production Runs', 'breadcrumbSection' => 'Production', 'activeItem' => 'Production Runs'])]
class ProductionRuns extends Component
{
    public string $selectedDate = '';

    public string $lineFilter = '';

    public ?int $selectedRunId = null;

    public ?int $confirmingCompletion = null;

    public function mount(): void
    {
        $requestedDate = request()->query('date');

        $this->selectedDate = $this->resolveDate(is_string($requestedDate) ? $requestedDate : null);
    }

    public function updatedSelectedDate(): void
    {
        $this->selectedDate = $this->resolveDate($this->selectedDate);
    }

    #[On('production-logged')]
    public function refreshTotals(): void
    {
        // No-op: receiving the event re-renders open runs with fresh log totals.
    }

    public function selectRun(int $scheduleId): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production');

        ProductionSchedule::query()->findOrFail($scheduleId);

        $this->selectedRunId = $scheduleId;
    }

    public function closeDetail(): void
    {
        $this->selectedRunId = null;
    }

    public function startRun(int $scheduleId): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production', write: true);

        $run = ProductionSchedule::query()->with('productionLine')->findOrFail($scheduleId);

        if ($run->status !== 'queued') {
            $this->addError('runTransition', 'Only queued runs can be started.');

            return;
        }

        $lineHasActiveRun = ProductionSchedule::query()
            ->where('production_line_id', $run->production_line_id)
            ->where('status', 'active')
            ->where('id', '!=', $run->id)
            ->exists();

        if ($lineHasActiveRun) {
            $this->addError('runTransition', ($run->productionLine?->name ?? 'This line').' already has an active run. Complete it before starting another.');

            return;
        }

        $run->update(['status' => 'active']);
        $this->confirmingCompletion = null;
    }

    public function completeRun(int $scheduleId): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production', write: true);

        $run = ProductionSchedule::query()->findOrFail($scheduleId);

        if ($run->status !== 'active') {
            $this->addError('runTransition', 'Only active runs can be completed.');

            return;
        }

        if ($run->logs()->count() === 0 && $this->confirmingCompletion !== $run->id) {
            $this->confirmingCompletion = $run->id;

            return;
        }

        $run->update(['status' => 'complete']);
        $this->confirmingCompletion = null;
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production');

        $runs = ProductionSchedule::query()
            ->with('productionLine')
            ->withSum('logs', 'units_logged')
            ->whereDate('scheduled_date', $this->selectedDate)
            ->whereIn('status', ['active', 'queued'])
            ->when($this->lineFilter !== '', function (Builder $query): void {
                $query->where('production_line_id', (int) $this->lineFilter);
            })
            ->orderBy('scheduled_time')
            ->get();

        return view('livewire.production-runs', [
            'runs' => $runs,
            'lines' => ProductionLine::query()->orderBy('name')->get(),
            'statusPresentation' => [
                'active' => ['label' => 'Active', 'class' => 'bg-blue-50 text-[#1d4ed8]', 'dotClass' => 'bg-blue-500', 'barClass' => 'bg-blue-500'],
                'queued' => ['label' => 'Queued', 'class' => 'bg-gray-100 text-gray-600', 'dotClass' => 'bg-gray-400', 'barClass' => 'bg-blue-500'],
            ],
            'totalTarget' => $runs->sum('target_units'),
            'totalActual' => $runs->sum('logs_sum_units_logged'),
            'canLog' => $this->canLog($user),
            'selectedRun' => $this->selectedRun(),
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
     * The run shown in the detail panel with its log entries, newest first.
     *
     * @return null|array{run:ProductionSchedule,logs:Collection<int, ProductionLog>}
     */
    private function selectedRun(): ?array
    {
        if ($this->selectedRunId === null) {
            return null;
        }

        $run = ProductionSchedule::query()
            ->with('productionLine')
            ->withSum('logs', 'units_logged')
            ->find($this->selectedRunId);

        if (! $run instanceof ProductionSchedule) {
            return null;
        }

        return [
            'run' => $run,
            'logs' => $run->logs()->with('operator')->latest('logged_at')->get(),
        ];
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
}
