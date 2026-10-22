<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class LeakageReportFlag
{
    private const REVIEW_PERCENT = 2;

    private const INVESTIGATE_PERCENT = 5;

    public static function fromVolumes(float $expected, float $actual): string
    {
        if ($expected <= 0) {
            return 'Normal';
        }

        $percentage = abs($actual - $expected) / $expected * 100;

        if ($percentage < self::REVIEW_PERCENT) {
            return 'Normal';
        }

        if ($percentage <= self::INVESTIGATE_PERCENT) {
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

        return match ($flag) {
            'Normal' => $query->where(function (Builder $query) use ($percentage): void {
                $query->where('expected_volume', '<=', 0)
                    ->orWhereRaw("{$percentage} < ?", [self::REVIEW_PERCENT]);
            }),
            'Review' => $query->where('expected_volume', '>', 0)
                ->whereRaw("{$percentage} >= ?", [self::REVIEW_PERCENT])
                ->whereRaw("{$percentage} <= ?", [self::INVESTIGATE_PERCENT]),
            'Investigate' => $query->where('expected_volume', '>', 0)
                ->whereRaw("{$percentage} > ?", [self::INVESTIGATE_PERCENT]),
            'Attention' => $query->where('expected_volume', '>', 0)
                ->whereRaw("{$percentage} >= ?", [self::REVIEW_PERCENT]),
        };
    }
}
