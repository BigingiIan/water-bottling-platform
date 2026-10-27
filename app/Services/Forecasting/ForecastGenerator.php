<?php

namespace App\Services\Forecasting;

use App\Models\Product;
use App\Services\MLForecastClient;
use Illuminate\Support\Collection;

class ForecastGenerator
{
    public function __construct(private MLForecastClient $client) {}

    /**
     * Requests tomorrow's forecast for every product SKU and stores the successful ones.
     *
     * @return Collection<string, ForecastOutcome>
     */
    public function generateTomorrow(): Collection
    {
        $targetDate = today()->addDay()->toDateString();

        return Product::query()
            ->orderBy('unit_type')
            ->distinct()
            ->pluck('unit_type')
            ->mapWithKeys(fn (string $sku): array => [$sku => $this->client->forecast($sku, $targetDate)]);
    }
}
