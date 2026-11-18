<?php

namespace App\Services;

use App\Services\Forecasting\ForecastOutcome;
use App\Services\Forecasting\ForecastReadiness;
use App\Services\Forecasting\ForecastStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MLForecastClient
{
    public const string UNAUTHORIZED_MESSAGE = 'The ML service rejected the access token. Set ML_FORECAST_TOKEN to match the ML_API_TOKEN the service runs with.';

    /**
     * @var array{reachable: bool, models: array<string, string>, error: ?string}|null
     */
    private ?array $health = null;

    public function __construct(private ForecastReadiness $readiness) {}

    public function predict(string $sku, string $date): ?float
    {
        return $this->forecast($sku, $date)->prediction;
    }

    public function forecast(string $sku, string $date): ForecastOutcome
    {
        $targetDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if ($targetDate === false || $targetDate->toDateString() !== $date) {
            return ForecastOutcome::failed(ForecastStatus::InvalidDate, $sku, $date);
        }

        if (! DB::table('products')->where('unit_type', $sku)->exists()) {
            return ForecastOutcome::failed(ForecastStatus::UnknownSku, $sku, $date);
        }

        $readiness = $this->readiness->forSkus([$sku], $targetDate)[$sku];

        if (! $readiness['ready']) {
            return $readiness['outcome'];
        }

        $historyDays = $readiness['requiredDays'];
        $featureRows = $readiness['features'];
        $historyStart = $targetDate->subDays($historyDays);
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
            $response = $this->request((int) config('services.ml_forecast.timeout', 3))
                ->post($this->serviceUrl().'/predict', $payload);
        } catch (ConnectionException $exception) {
            Log::warning('ML forecast service is unavailable.', [
                'sku' => $sku,
                'date' => $date,
                'message' => $exception->getMessage(),
            ]);

            return ForecastOutcome::failed(ForecastStatus::ServiceUnreachable, $sku, $date, $this->serviceUrl());
        }

        if (! $response->successful()) {
            Log::warning('ML forecast service returned an unsuccessful response.', [
                'sku' => $sku,
                'date' => $date,
                'status' => $response->status(),
            ]);

            if ($response->status() === 401) {
                return ForecastOutcome::failed(ForecastStatus::Unauthorized, $sku, $date);
            }

            $detail = $response->json('detail');

            if ($response->status() === 503 && is_string($detail) && str_contains($detail, 'No trained model')) {
                return ForecastOutcome::failed(ForecastStatus::NoModelForSku, $sku, $date);
            }

            return ForecastOutcome::failed(ForecastStatus::ServiceError, $sku, $date, 'HTTP '.$response->status());
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

            return ForecastOutcome::failed(ForecastStatus::InvalidResponse, $sku, $date);
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

            return ForecastOutcome::failed(ForecastStatus::InvalidResponse, $sku, $date);
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

            return ForecastOutcome::failed(ForecastStatus::NotStored, $sku, $date);
        }

        return ForecastOutcome::generated($sku, $date, (float) $prediction, $modelVersion, (float) $rmseScore);
    }

    /**
     * Reachability and the model version served per SKU, checked once per request.
     *
     * @return array{reachable: bool, models: array<string, string>, error: ?string}
     */
    public function health(): array
    {
        if ($this->health !== null) {
            return $this->health;
        }

        try {
            $response = $this->request(2)->get($this->serviceUrl().'/health');
        } catch (ConnectionException) {
            return $this->health = ['reachable' => false, 'models' => [], 'error' => "No response from {$this->serviceUrl()}."];
        }

        if ($response->status() === 401) {
            return $this->health = ['reachable' => false, 'models' => [], 'error' => self::UNAUTHORIZED_MESSAGE];
        }

        if ($response->status() === 404) {
            return $this->health = ['reachable' => false, 'models' => [], 'error' => 'The ML service is running an older version without a health check. Restart it to load the latest code.'];
        }

        if (! $response->successful()) {
            return $this->health = ['reachable' => false, 'models' => [], 'error' => 'The ML service answered with HTTP '.$response->status().'.'];
        }

        $models = collect((array) $response->json('models', []))
            ->filter(fn (mixed $version, mixed $sku): bool => is_string($sku) && is_string($version) && $version !== '')
            ->all();

        return $this->health = ['reachable' => true, 'models' => $models, 'error' => null];
    }

    /**
     * Monthly mean temperatures each served bundle scores with (GET /climatology), keyed by SKU.
     * Returns null when the service can't be reached or answers with something unusable.
     *
     * @return array<string, array{source: string, months: array<string, float>}>|null
     */
    public function climatology(): ?array
    {
        try {
            $response = $this->request(3)->get($this->serviceUrl().'/climatology');
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful() || ! is_array($response->json('models'))) {
            return null;
        }

        $climatology = [];

        foreach ((array) $response->json('models') as $sku => $model) {
            $months = is_array($model) ? ($model['months'] ?? null) : null;

            if (! is_string($sku) || ! is_array($months) || array_diff(array_map('strval', range(1, 12)), array_map('strval', array_keys($months))) !== []) {
                continue;
            }

            $climatology[$sku] = [
                'source' => is_string($model['temperature_source'] ?? null) ? $model['temperature_source'] : 'monthly_mean',
                'months' => array_map('floatval', array_intersect_key($months, array_flip(array_map('strval', range(1, 12))))),
            ];
        }

        return $climatology;
    }

    public function serviceUrl(): string
    {
        return rtrim((string) config('services.ml_forecast.url'), '/');
    }

    /**
     * Every call to the ML service starts here, so the shared token (when configured) is always sent.
     */
    private function request(int $timeoutSeconds): PendingRequest
    {
        $request = Http::connectTimeout(1)->timeout($timeoutSeconds);
        $token = trim((string) config('services.ml_forecast.token'));

        return $token === '' ? $request : $request->withToken($token);
    }
}
