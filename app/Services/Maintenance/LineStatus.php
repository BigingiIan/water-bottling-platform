<?php

namespace App\Services\Maintenance;

use App\Models\Equipment;
use App\Models\ProductionLine;
use Illuminate\Support\Collection;

/**
 * Each production line's condition as the Production pages see it: the worst status of its equipment,
 * which machines are down, and the downtime recorded in the last 30 days.
 */
class LineStatus
{
    public const DOWNTIME_DAYS = 30;

    public function __construct(private MaintenanceService $maintenance) {}

    /**
     * @return Collection<int, array{line: ProductionLine, status: ?string, disruptive: Collection<int, Equipment>, downtimeMinutes: int}>
     */
    public function all(): Collection
    {
        $downtime = $this->maintenance->downtimeByLine(self::DOWNTIME_DAYS);

        return ProductionLine::query()
            ->with(['equipment' => fn ($query) => $query->orderBy('code')])
            ->orderBy('name')
            ->get()
            ->map(fn (ProductionLine $line): array => $this->summarise($line, $downtime[$line->id] ?? 0));
    }

    /**
     * The warning to show when a run is scheduled on this line, or null when nothing on it is down.
     */
    public function warningFor(?int $lineId): ?string
    {
        if ($lineId === null) {
            return null;
        }

        $line = ProductionLine::query()->with('equipment')->find($lineId);

        if ($line === null) {
            return null;
        }

        $summary = $this->summarise($line, 0);

        if ($summary['disruptive']->isEmpty()) {
            return null;
        }

        return __('maintenance.line_status.warning', [
            'line' => $line->name,
            'status' => strtolower(__('maintenance.equipment_statuses.'.$summary['status'])),
            'equipment' => $summary['disruptive']->map(fn (Equipment $equipment): string => "{$equipment->code} {$equipment->name} (".strtolower($equipment->statusLabel()).')')->implode(', '),
        ]);
    }

    /**
     * @return array{line: ProductionLine, status: ?string, disruptive: Collection<int, Equipment>, downtimeMinutes: int}
     */
    private function summarise(ProductionLine $line, int $downtimeMinutes): array
    {
        $worst = $line->equipment->sortByDesc(fn (Equipment $equipment): int => Equipment::STATUS_SEVERITY[$equipment->status] ?? 0)->first();

        return [
            'line' => $line,
            'status' => $worst?->status,
            'disruptive' => $line->equipment->filter(fn (Equipment $equipment): bool => $equipment->isDisruptive())->values(),
            'downtimeMinutes' => $downtimeMinutes,
        ];
    }
}
