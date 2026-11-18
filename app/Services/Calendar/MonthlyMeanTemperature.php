<?php

namespace App\Services\Calendar;

use App\Services\MLForecastClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Fills temperature_c with the monthly mean the served bundle uses, so the value Laravel stores is the one
 * the model actually scores with.
 *
 * The means come from the ML service's GET /climatology, cached for a day. When the service can't be
 * reached, the same means are read from the bundle manifests in ML_MODEL_DIR, so closing a sales day or
 * seeding still works while the service is down.
 */
class MonthlyMeanTemperature implements TemperatureSource
{
    public const CACHE_KEY = 'calendar.climatology';

    public const CACHE_SECONDS = 86400;

    /**
     * @var array<string, array<string, float>>|null
     */
    private ?array $serviceMeans = null;

    /**
     * @var array<string, array<string, float>|string>
     */
    private array $manifestMeans = [];

    public function __construct(private MLForecastClient $client) {}

    public function name(): string
    {
        return 'monthly_mean';
    }

    public function temperatureFor(string $sku, CarbonInterface $date): ?float
    {
        $months = $this->meansFor($sku);

        return is_array($months) ? round($months[(string) $date->month], 2) : null;
    }

    public function unavailableReason(string $sku): ?string
    {
        $months = $this->meansFor($sku);

        return is_string($months) ? $months : null;
    }

    /**
     * @return array<string, float>|string The twelve monthly means, or why they are unavailable.
     */
    private function meansFor(string $sku): array|string
    {
        $serviceMeans = $this->serviceMeans ??= $this->loadServiceMeans();

        return $serviceMeans[$sku] ?? ($this->manifestMeans[$sku] ??= $this->loadManifestMeans($sku));
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function loadServiceMeans(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $climatology = $this->client->climatology();
        } catch (Throwable) {
            $climatology = null;
        }

        if ($climatology === null) {
            return [];
        }

        $means = array_map(fn (array $model): array => $model['months'], $climatology);
        Cache::put(self::CACHE_KEY, $means, self::CACHE_SECONDS);

        return $means;
    }

    /**
     * @return array<string, float>|string
     */
    private function loadManifestMeans(string $sku): array|string
    {
        $path = rtrim((string) config('services.ml_forecast.model_dir'), '/\\')."/{$sku}/manifest.json";

        if (! is_file($path)) {
            return "Model manifest not found for {$sku}: {$path}. Set ML_MODEL_DIR to the served bundles, or start the forecast service.";
        }

        try {
            $months = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['temperature_climatology'] ?? null;
        } catch (Throwable) {
            return "The {$sku} manifest could not be read: {$path}";
        }

        if (! is_array($months) || array_diff(array_map('strval', range(1, 12)), array_map('strval', array_keys($months))) !== []) {
            return "The {$sku} manifest has no complete temperature_climatology (months 1-12): {$path}";
        }

        return array_map('floatval', $months);
    }
}
