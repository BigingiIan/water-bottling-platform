<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MLForecastClient
{
    public function predict(string $sku, string $date): ?float
    {
        $targetDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if ($targetDate === false || $targetDate->toDateString() !== $date) {
            return null;
        }

        if (! DB::table('products')->where('unit_type', $sku)->exists()) {
            return null;
        }

        $historyDays = app(SettingsService::class)->get('forecast.min_coverage_days', 14);
        $historyStart = $targetDate->subDays($historyDays);
        $featureRows = DB::table('daily_sku_features')
            ->where('product_sku', $sku)
            ->whereBetween('date', [$historyStart->toDateString(), $date])
            ->get()
            ->keyBy('date');

        $salesByDate = DB::table('sales_transactions')
            ->join('warehouse_stock', 'warehouse_stock.id', '=', 'sales_transactions.stock_id')
            ->join('products', 'products.id', '=', 'warehouse_stock.product_id')
            ->where('products.unit_type', $sku)
            ->whereBetween('sales_transactions.transaction_date', [
                $historyStart->startOfDay(),
                $targetDate->subDay()->endOfDay(),
            ])
            ->selectRaw('DATE(sales_transactions.transaction_date) as sales_date, SUM(sales_transactions.quantity) as units_sold')
            ->groupBy('sales_date')
            ->pluck('units_sold', 'sales_date');

        $history = [];

        for ($offset = $historyDays; $offset > 0; $offset--) {
            $historyDate = $targetDate->subDays($offset)->toDateString();
            $feature = $featureRows->get($historyDate);

            if (! $this->hasCompleteFeatures($feature) || ! $feature->sales_data_covered) {
                return null;
            }

            $history[] = [
                'date' => $historyDate,
                'units_sold' => (float) $salesByDate->get($historyDate, 0),
                'is_promotion' => (bool) $feature->is_promotion,
                'is_public_holiday' => (bool) $feature->is_public_holiday,
                'is_school_term' => (bool) $feature->is_school_term,
                'temperature_c' => (float) $feature->temperature_c,
            ];
        }

        $targetFeatures = $featureRows->get($date);

        if (! $this->hasCompleteFeatures($targetFeatures)) {
            return null;
        }

        $payload = [
            'sku' => $sku,
            'date' => $date,
            'history' => $history,
            'is_promotion' => (bool) $targetFeatures->is_promotion,
            'is_public_holiday' => (bool) $targetFeatures->is_public_holiday,
            'is_school_term' => (bool) $targetFeatures->is_school_term,
            'temperature_c' => (float) $targetFeatures->temperature_c,
        ];

        try {
            $response = Http::connectTimeout(1)
                ->timeout((int) config('services.ml_forecast.timeout', 3))
                ->post(rtrim((string) config('services.ml_forecast.url'), '/').'/predict', $payload);
        } catch (ConnectionException $exception) {
            Log::warning('ML forecast service is unavailable.', [
                'sku' => $sku,
                'date' => $date,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('ML forecast service returned an unsuccessful response.', [
                'sku' => $sku,
                'date' => $date,
                'status' => $response->status(),
            ]);

            return null;
        }

        $prediction = $response->json('predicted_units_sold');
        $modelVersion = $response->json('model_version');
        $rmseScore = $response->json('rmse_score');

        if (! is_numeric($prediction)
            || ! is_finite((float) $prediction)
            || (float) $prediction < 0
            || (float) $prediction > 9999999999.99) {
            Log::warning('ML forecast service returned an invalid prediction.', [
                'sku' => $sku,
                'date' => $date,
            ]);

            return null;
        }

        if (! is_string($modelVersion) || trim($modelVersion) === ''
            || mb_strlen($modelVersion) > 255
            || ! is_numeric($rmseScore)
            || ! is_finite((float) $rmseScore)
            || (float) $rmseScore < 0
            || (float) $rmseScore > 9999.9999) {
            Log::warning('ML forecast service returned invalid model metadata.', [
                'sku' => $sku,
                'date' => $date,
            ]);

            return null;
        }

        $productId = DB::table('products')
            ->where('unit_type', $sku)
            ->orderBy('id')
            ->value('id');

        try {
            DB::table('forecast_records')->upsert(
                [[
                    'product_id' => $productId,
                    'forecast_date' => $date,
                    'predicted_demand' => $prediction,
                    'horizon_days' => 1,
                    'model_version' => $modelVersion,
                    'rmse_score' => $rmseScore,
                    'generated_at' => now()->utc(),
                ]],
                ['product_id', 'forecast_date', 'model_version'],
                ['predicted_demand', 'horizon_days', 'rmse_score', 'generated_at'],
            );
        } catch (QueryException $exception) {
            Log::error('ML forecast could not be persisted.', [
                'sku' => $sku,
                'date' => $date,
                'model_version' => $modelVersion,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        return (float) $prediction;
    }

    private function hasCompleteFeatures(?object $feature): bool
    {
        return $feature !== null
            && $feature->is_promotion !== null
            && $feature->is_public_holiday !== null
            && $feature->is_school_term !== null
            && $feature->temperature_c !== null;
    }
}
