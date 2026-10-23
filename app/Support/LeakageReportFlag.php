<?php

namespace App\Support;

use App\Services\Leakage\LeakageDetector;
use Illuminate\Database\Eloquent\Builder;

/**
 * Display labels for leakage severity. Thresholds and formulas live in LeakageDetector.
 */
class LeakageReportFlag
{
    public static function fromVolumes(float $expected, float $actual): string
    {
        $grading = app(LeakageDetector::class)->gradeManual(sprintf('%.3F', $expected), sprintf('%.3F', $actual));

        return self::fromSeverity($grading['severity']);
    }

    public static function fromSeverity(?string $severity): string
    {
        return match ($severity) {
            'investigate' => 'Investigate',
            'review' => 'Review',
            default => 'Normal',
        };
    }

    /**
     * Filters on the severity stored when the report was graded.
     */
    public static function applyFilter(Builder $query, ?string $flag): Builder
    {
        return match ($flag) {
            'Normal' => $query->whereNull('severity'),
            'Review' => $query->where('severity', 'review'),
            'Investigate' => $query->where('severity', 'investigate'),
            'Attention' => $query->whereNotNull('severity'),
            default => $query,
        };
    }
}
