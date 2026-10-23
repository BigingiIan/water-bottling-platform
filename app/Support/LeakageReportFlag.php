<?php

namespace App\Support;

use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;

class LeakageReportFlag
{
    public static function fromVolumes(float $expected, float $actual): string
    {
        if ($expected <= 0) {
            return 'Normal';
        }

        $percentage = abs($actual - $expected) / $expected * 100;
        $settings = app(SettingsService::class);
        $reviewPercent = $settings->get('leakage.review_percent', 2.0);
        $investigatePercent = $settings->get('leakage.investigate_percent', 5.0);

        if ($percentage < $reviewPercent) {
            return 'Normal';
        }

        if ($percentage <= $investigatePercent) {
            return 'Review';
        }

        return 'Investigate';
    }

    public static function applyFilter(Builder $query, ?string $flag): Builder
    {
        if (! in_array($flag, ['Normal', 'Review', 'Investigate', 'Attention'], true)) {
            return $query;
        }

        $percentage = '(ABS(discrepancy) * 100.0 / expected_volume)';
        $settings = app(SettingsService::class);
        $reviewPercent = $settings->get('leakage.review_percent', 2.0);
        $investigatePercent = $settings->get('leakage.investigate_percent', 5.0);

        return match ($flag) {
            'Normal' => $query->where(function (Builder $query) use ($percentage, $reviewPercent): void {
                $query->where('expected_volume', '<=', 0)
                    ->orWhereRaw("{$percentage} < CAST(? AS DECIMAL)", [$reviewPercent]);
            }),
            'Review' => $query->where('expected_volume', '>', 0)
                ->whereRaw("{$percentage} >= CAST(? AS DECIMAL)", [$reviewPercent])
                ->whereRaw("{$percentage} <= CAST(? AS DECIMAL)", [$investigatePercent]),
            'Investigate' => $query->where('expected_volume', '>', 0)
                ->whereRaw("{$percentage} > CAST(? AS DECIMAL)", [$investigatePercent]),
            'Attention' => $query->where('expected_volume', '>', 0)
                ->whereRaw("{$percentage} >= CAST(? AS DECIMAL)", [$reviewPercent]),
        };
    }
}
