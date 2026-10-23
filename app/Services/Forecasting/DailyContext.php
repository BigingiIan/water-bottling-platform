<?php

namespace App\Services\Forecasting;

use App\Services\KenyanCalendarFeatures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Context values for daily_sku_features that do not come from sales: the Kenyan calendar and the
 * served bundles' monthly temperature means.
 */
class DailyContext
{
    /**
     * @var array<string, array<string, float>>
     */
    private array $climatology = [];

    public function __construct(private KenyanCalendarFeatures $calendar) {}

    public function isPublicHoliday(string $date): bool
    {
        return $this->calendar->isPublicHoliday(Carbon::parse($date));
    }

    public function isSchoolTerm(string $date): bool
    {
        return $this->calendar->isSchoolTerm(Carbon::parse($date));
    }

    /**
     * The served model ignores the request temperature and scores with monthly means, but Laravel requires
     * a complete feature row. Source: the bundle manifest's temperature_climatology for the date's month.
     */
    public function temperature(string $sku, string $date): float
    {
        $months = $this->climatology[$sku] ??= $this->loadClimatology($sku);

        return round($months[(string) CarbonImmutable::parse($date)->month], 2);
    }

    /**
     * Fails before any write when a SKU's bundle has no usable climatology.
     *
     * @param  array<int, string>  $skus
     */
    public function assertClimatologyFor(array $skus): void
    {
        foreach ($skus as $sku) {
            $this->climatology[$sku] ??= $this->loadClimatology($sku);
        }
    }

    /**
     * @return array<string, float>
     */
    private function loadClimatology(string $sku): array
    {
        $path = rtrim((string) config('services.ml_forecast.model_dir'), '/\\')."/{$sku}/manifest.json";

        if (! is_file($path)) {
            throw new RuntimeException("Model manifest not found for {$sku}: {$path}. Set ML_MODEL_DIR to the served bundles.");
        }

        $months = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['temperature_climatology'] ?? null;

        if (! is_array($months) || array_diff(array_map('strval', range(1, 12)), array_map('strval', array_keys($months))) !== []) {
            throw new RuntimeException("The {$sku} manifest has no complete temperature_climatology (months 1-12): {$path}");
        }

        return array_map('floatval', $months);
    }
}
