<?php

namespace App\Livewire;

use App\Models\ProductionLine;
use App\Models\ProductionSchedule;
use App\Models\User;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('components.app-layout', ['title' => 'Production History', 'breadcrumbSection' => 'Production', 'activeItem' => 'History'])]
class ProductionHistory extends Component
{
    public string $dateFrom = '';

    public string $dateTo = '';

    public string $skuFilter = '';

    public string $lineFilter = '';

    public string $qualityFilter = '';

    public function mount(): void
    {
        $query = request()->query();

        $singleDate = is_string($query['date'] ?? null) ? $query['date'] : null;
        $from = is_string($query['date_from'] ?? null) ? $query['date_from'] : $singleDate;
        $to = is_string($query['date_to'] ?? null) ? $query['date_to'] : $singleDate;

        $this->dateTo = $this->resolveDate($to);
        $this->dateFrom = $this->resolveDate($from, today()->subDays(6)->toDateString());
    }

    public function updatedDateFrom(): void
    {
        $this->dateFrom = $this->resolveDate($this->dateFrom, today()->subDays(6)->toDateString());
    }

    public function updatedDateTo(): void
    {
        $this->dateTo = $this->resolveDate($this->dateTo);
    }

    public function exportCsv(): StreamedResponse
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production');

        [$from, $to] = $this->orderedRange();
        $schedules = $this->filteredSchedules($from, $to);

        return response()->streamDownload(function () use ($schedules): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['date', 'time', 'sku', 'line', 'target', 'actual', 'variance_pct', 'entries', 'flagged', 'status']);

            foreach ($schedules as $schedule) {
                $actual = (int) ($schedule->logs_sum_units_logged ?? 0);
                $target = (int) $schedule->target_units;

                fputcsv($output, [
                    $schedule->scheduled_date->toDateString(),
                    mb_substr((string) $schedule->scheduled_time, 0, 5),
                    $schedule->product_sku,
                    $schedule->productionLine?->name ?? '',
                    $target,
                    $actual,
                    $this->variancePct($actual, $target),
                    $schedule->logs_count,
                    $schedule->flagged_logs_count,
                    $schedule->status,
                ]);
            }

            fclose($output);
        }, "production-history-{$from}_{$to}.csv", ['Content-Type' => 'text/csv']);
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'production');

        [$from, $to] = $this->orderedRange();
        $schedules = $this->filteredSchedules($from, $to);

        $totalTarget = (int) $schedules->sum('target_units');
        $totalActual = (int) $schedules->sum('logs_sum_units_logged');

        return view('livewire.production-history', [
            'schedules' => $schedules,
            'statusPresentation' => [
                'complete' => ['label' => 'Complete', 'class' => 'bg-green-50 text-[#15803d]', 'dotClass' => 'bg-green-500'],
                'active' => ['label' => 'Active', 'class' => 'bg-blue-50 text-[#1d4ed8]', 'dotClass' => 'bg-blue-500'],
                'queued' => ['label' => 'Queued', 'class' => 'bg-gray-100 text-gray-600', 'dotClass' => 'bg-gray-400'],
            ],
            'skus' => ProductionSchedule::query()->distinct()->orderBy('product_sku')->pluck('product_sku'),
            'lines' => ProductionLine::query()->orderBy('name')->get(),
            'totalTarget' => $totalTarget,
            'totalActual' => $totalActual,
            'totalVariancePct' => $this->variancePct($totalActual, $totalTarget),
        ]);
    }

    /**
     * @return Collection<int, ProductionSchedule>
     */
    private function filteredSchedules(string $from, string $to): Collection
    {
        return ProductionSchedule::query()
            ->with('productionLine')
            ->withSum('logs', 'units_logged')
            ->withCount('logs')
            ->withCount(['logs as flagged_logs_count' => function (Builder $query): void {
                $query->where('quality_status', 'flagged');
            }])
            ->whereDate('scheduled_date', '>=', $from)
            ->whereDate('scheduled_date', '<=', $to)
            ->when($this->skuFilter !== '', function (Builder $query): void {
                $query->where('product_sku', $this->skuFilter);
            })
            ->when($this->lineFilter !== '', function (Builder $query): void {
                $query->where('production_line_id', (int) $this->lineFilter);
            })
            ->when(in_array($this->qualityFilter, ['passed', 'flagged'], true), function (Builder $query): void {
                $query->whereHas('logs', function (Builder $query): void {
                    $query->where('quality_status', $this->qualityFilter);
                });
            })
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->get();
    }

    /**
     * @return array{0:string,1:string}
     */
    private function orderedRange(): array
    {
        if ($this->dateFrom !== '' && $this->dateTo !== '' && $this->dateFrom > $this->dateTo) {
            return [$this->dateTo, $this->dateFrom];
        }

        return [$this->dateFrom, $this->dateTo];
    }

    private function variancePct(int $actual, int $target): ?float
    {
        if ($target <= 0) {
            return null;
        }

        return round(($actual - $target) / $target * 100, 1);
    }

    private function resolveDate(?string $value, ?string $default = null): string
    {
        if ($value !== null && $value !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                // Fall through to the default.
            }
        }

        return $default ?? today()->toDateString();
    }
}
