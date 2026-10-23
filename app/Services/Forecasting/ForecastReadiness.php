<?php

namespace App\Services\Forecasting;

use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ForecastReadiness
{
    public function __construct(private SettingsService $settings) {}

    public function requiredDays(): int
    {
        return (int) $this->settings->get('forecast.min_coverage_days', 14);
    }

    /**
     * Checks every SKU's history window and target-day context with a single query.
     *
     * @param  array<int, string>  $skus
     * @return array<string, array{ready: bool, outcome: ?ForecastOutcome, coveredDays: int, requiredDays: int, features: Collection<string, object>}>
     */
    public function forSkus(array $skus, CarbonImmutable $targetDate): array
    {
        $requiredDays = $this->requiredDays();
        $historyStart = $targetDate->subDays($requiredDays);
        $target = $targetDate->toDateString();
        $featuresBySku = DB::table('daily_sku_features')
            ->whereIn('product_sku', $skus)
            ->whereBetween('date', [$historyStart->toDateString(), $target])
            ->get()
            ->groupBy('product_sku');
        $results = [];

        foreach ($skus as $sku) {
            $features = ($featuresBySku->get($sku) ?? collect())->keyBy(fn (object $feature): string => substr((string) $feature->date, 0, 10));
            $missingDates = [];

            for ($offset = $requiredDays; $offset > 0; $offset--) {
                $historyDate = $targetDate->subDays($offset)->toDateString();
                $feature = $features->get($historyDate);

                if (! self::hasCompleteFeatures($feature) || ! $feature->sales_data_covered) {
                    $missingDates[] = $historyDate;
                }
            }

            $outcome = null;

            if ($missingDates !== []) {
                $outcome = new ForecastOutcome(ForecastStatus::MissingCoveredDays, $sku, $target, missingDates: $missingDates, requiredDays: $requiredDays);
            } elseif (! self::hasCompleteFeatures($features->get($target))) {
                $outcome = ForecastOutcome::failed(ForecastStatus::MissingContext, $sku, $target);
            }

            $results[$sku] = [
                'ready' => $outcome === null,
                'outcome' => $outcome,
                'coveredDays' => $requiredDays - count($missingDates),
                'requiredDays' => $requiredDays,
                'features' => $features,
            ];
        }

        return $results;
    }

    public static function hasCompleteFeatures(?object $feature): bool
    {
        return $feature !== null
            && $feature->is_promotion !== null
            && $feature->is_public_holiday !== null
            && $feature->is_school_term !== null
            && $feature->temperature_c !== null;
    }
}
