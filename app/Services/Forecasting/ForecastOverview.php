<?php

namespace App\Services\Forecasting;

use App\Models\ForecastRecord;
use App\Models\Product;
use App\Services\MLForecastClient;
use App\Support\SettingsDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the per-SKU forecast summaries shown on the Forecasting page and the dashboard,
 * using a fixed number of queries regardless of how many SKUs exist.
 */
class ForecastOverview
{
    public const HISTORY_DAYS = 14;

    public function __construct(
        private ForecastReadiness $readiness,
        private MLForecastClient $client,
        private SettingsDate $dates,
    ) {}

    /**
     * @return Collection<int, array{
     *     product: Product,
     *     tomorrow: ?ForecastRecord,
     *     latest: ?ForecastRecord,
     *     outcome: ForecastOutcome,
     *     coveredDays: int,
     *     requiredDays: int,
     *     servedVersion: ?string,
     *     points: array<int, array{date: string, label: string, actual: ?float, forecast: ?float}>
     * }>
     */
    public function cards(): Collection
    {
        $tomorrow = CarbonImmutable::today()->addDay();
        $historyStart = CarbonImmutable::today()->subDays(self::HISTORY_DAYS);
        $historyEnd = CarbonImmutable::today()->subDay();
        $products = $this->products();

        if ($products->isEmpty()) {
            return collect();
        }

        $productIds = $products->pluck('id')->all();
        $recordsByProduct = $this->latestRecordsByProductAndDate($productIds, $historyStart, $tomorrow);
        $latestByProduct = ForecastRecord::query()
            ->whereIn('id', ForecastRecord::query()->selectRaw('MAX(id)')->whereIn('product_id', $productIds)->groupBy('product_id'))
            ->get()
            ->keyBy('product_id');
        $salesByProduct = DB::table('sales_transactions')
            ->join('warehouse_stock', 'warehouse_stock.id', '=', 'sales_transactions.stock_id')
            ->whereIn('warehouse_stock.product_id', $productIds)
            ->whereBetween('sales_transactions.transaction_date', [$historyStart->startOfDay(), $historyEnd->endOfDay()])
            ->selectRaw('warehouse_stock.product_id as product_id, DATE(sales_transactions.transaction_date) as sales_date, SUM(sales_transactions.quantity) as units_sold')
            ->groupBy('warehouse_stock.product_id', 'sales_date')
            ->get()
            ->groupBy('product_id');
        $readiness = $this->readiness->forSkus($products->pluck('unit_type')->unique()->values()->all(), $tomorrow);
        $health = $this->client->health();

        return $products->map(function (Product $product) use ($tomorrow, $historyStart, $recordsByProduct, $latestByProduct, $salesByProduct, $readiness, $health): array {
            $records = $recordsByProduct->get($product->id, collect());
            $sales = ($salesByProduct->get($product->id) ?? collect())->pluck('units_sold', 'sales_date');
            $tomorrowRecord = $records->get($tomorrow->toDateString());
            $skuReadiness = $readiness[$product->unit_type];
            $points = [];

            for ($day = 0; $day < self::HISTORY_DAYS; $day++) {
                $date = $historyStart->addDays($day)->toDateString();
                $points[] = [
                    'date' => $date,
                    'label' => $this->dates->formatDate($date),
                    'actual' => $sales->has($date) ? (float) $sales->get($date) : 0.0,
                    'forecast' => $records->has($date) ? (float) $records->get($date)->predicted_demand : null,
                ];
            }

            return [
                'product' => $product,
                'tomorrow' => $tomorrowRecord,
                'latest' => $tomorrowRecord ?? $latestByProduct->get($product->id),
                'outcome' => $this->explain($product->unit_type, $tomorrow->toDateString(), $tomorrowRecord, $skuReadiness['outcome'], $health),
                'coveredDays' => $skuReadiness['coveredDays'],
                'requiredDays' => $skuReadiness['requiredDays'],
                'servedVersion' => $health['models'][$product->unit_type] ?? null,
                'points' => $points,
            ];
        })->values();
    }

    /**
     * Tomorrow's forecast against current stock for each product.
     *
     * @return Collection<int, array{product: Product, forecast: ?float, units: float, coverDays: ?float}>
     */
    public function tomorrowCover(): Collection
    {
        $tomorrow = CarbonImmutable::today()->addDay();
        $products = $this->products();

        if ($products->isEmpty()) {
            return collect();
        }

        $records = $this->latestRecordsByProductAndDate($products->pluck('id')->all(), $tomorrow, $tomorrow);
        $totals = DB::table('warehouse_stock')
            ->whereIn('product_id', $products->pluck('id'))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(current_level) as total_units')
            ->pluck('total_units', 'product_id');

        return $products->map(function (Product $product) use ($records, $totals, $tomorrow): array {
            $record = $records->get($product->id, collect())->get($tomorrow->toDateString());
            $forecast = $record === null ? null : (float) $record->predicted_demand;
            $units = (float) ($totals[$product->id] ?? 0);

            return [
                'product' => $product,
                'forecast' => $forecast,
                'units' => $units,
                'coverDays' => $forecast !== null && $forecast > 0 ? $units / $forecast : null,
            ];
        })->values();
    }

    /**
     * @param  array{reachable: bool, models: array<string, string>, error: ?string}  $health
     */
    private function explain(string $sku, string $targetDate, ?ForecastRecord $tomorrowRecord, ?ForecastOutcome $readinessOutcome, array $health): ForecastOutcome
    {
        if ($tomorrowRecord !== null) {
            return ForecastOutcome::generated($sku, $targetDate, (float) $tomorrowRecord->predicted_demand, (string) $tomorrowRecord->model_version, (float) $tomorrowRecord->rmse_score);
        }

        if ($readinessOutcome !== null) {
            return $readinessOutcome;
        }

        if (! $health['reachable']) {
            return ForecastOutcome::failed(ForecastStatus::ServiceUnreachable, $sku, $targetDate, $this->client->serviceUrl());
        }

        if (! array_key_exists($sku, $health['models'])) {
            return ForecastOutcome::failed(ForecastStatus::NoModelForSku, $sku, $targetDate);
        }

        return ForecastOutcome::failed(ForecastStatus::NotGenerated, $sku, $targetDate);
    }

    /**
     * @return Collection<int, Product>
     */
    private function products(): Collection
    {
        return Product::query()->orderBy('unit_type')->orderBy('id')->get(['id', 'name', 'unit_type', 'reorder_threshold']);
    }

    /**
     * The newest one-day-ahead record per product and forecast date.
     *
     * @param  array<int, int>  $productIds
     * @return Collection<int, Collection<string, ForecastRecord>>
     */
    private function latestRecordsByProductAndDate(array $productIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return ForecastRecord::query()
            ->whereIn('product_id', $productIds)
            ->where('horizon_days', 1)
            ->whereDate('forecast_date', '>=', $from->toDateString())
            ->whereDate('forecast_date', '<=', $to->toDateString())
            ->latestGenerated()
            ->get()
            ->groupBy('product_id')
            ->map(fn (Collection $records): Collection => $records
                ->groupBy(fn (ForecastRecord $record): string => $record->forecast_date->toDateString())
                ->map(fn (Collection $dateRecords): ForecastRecord => $dateRecords->first()));
    }
}
