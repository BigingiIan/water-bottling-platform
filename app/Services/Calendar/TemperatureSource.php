<?php

namespace App\Services\Calendar;

use Carbon\CarbonInterface;

/**
 * Where daily_sku_features.temperature_c comes from.
 *
 * Only MonthlyMeanTemperature exists, on purpose. The served model scores with the monthly mean
 * temperatures stored in its bundle (temperature_source = monthly_mean) and ignores the value Laravel
 * sends, so a live weather feed would change nothing today. A weather-based source would only matter if
 * the model were retrained on observed or forecast temperatures; add an implementation then, not before.
 */
interface TemperatureSource
{
    /**
     * The value stored in daily_sku_features.temperature_source, such as "monthly_mean".
     */
    public function name(): string;

    /**
     * The temperature for the SKU on the date, or null when the source has nothing for that SKU.
     */
    public function temperatureFor(string $sku, CarbonInterface $date): ?float;

    /**
     * Why temperatureFor() returned null for the SKU, in words an operator can act on.
     */
    public function unavailableReason(string $sku): ?string;
}
