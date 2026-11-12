<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DailySkuFeature;
use App\Models\Role;
use App\Models\SchoolTerm;
use App\Models\User;
use App\Services\Forecasting\ForecastReadiness;
use App\Services\Forecasting\ForecastStatus;
use App\Services\MLForecastClient;
use Carbon\CarbonImmutable;
use Database\Seeders\ForecastingDemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ForecastingDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const SKUS = ['10L', '1L', '20L', '500ml', '5L'];

    private string $modelDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->seed(RoleSeeder::class);
        User::factory()->create(['role_id' => Role::query()->where('slug', 'administrator')->valueOrFail('id')]);

        $this->modelDirectory = storage_path('framework/testing/forecasting-demo-manifests');
        foreach (self::SKUS as $sku) {
            File::ensureDirectoryExists("{$this->modelDirectory}/{$sku}");
            File::put("{$this->modelDirectory}/{$sku}/manifest.json", json_encode([
                'model_version' => 'test',
                'temperature_climatology' => array_combine(
                    array_map('strval', range(1, 12)),
                    array_map(fn (int $month): float => 20 + $month / 10, range(1, 12)),
                ),
            ]));
        }
        config(['services.ml_forecast.model_dir' => $this->modelDirectory]);
        SchoolTerm::factory()->create(['year' => 2026, 'term' => 3, 'start_date' => '2026-08-25', 'end_date' => '2026-10-31']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->modelDirectory);

        parent::tearDown();
    }

    public function test_it_seeds_at_least_14_covered_days_per_sku_and_complete_context_for_tomorrow(): void
    {
        app(ForecastingDemoSeeder::class)->run();

        $tomorrow = CarbonImmutable::parse('2026-10-23');
        $readiness = app(ForecastReadiness::class)->forSkus(self::SKUS, $tomorrow);

        foreach (self::SKUS as $sku) {
            $this->assertTrue($readiness[$sku]['ready'], "{$sku} is not ready: ".$readiness[$sku]['outcome']?->message());
            $this->assertGreaterThanOrEqual(14, DailySkuFeature::query()->where('product_sku', $sku)->where('sales_data_covered', true)->count());
            $this->assertSame(60, DailySkuFeature::query()->where('product_sku', $sku)->where('sales_data_covered', true)->count());

            $tomorrowRow = DailySkuFeature::query()->where('product_sku', $sku)->whereDate('date', $tomorrow->toDateString())->sole();
            $this->assertFalse($tomorrowRow->sales_data_covered);
            $this->assertFalse((bool) $tomorrowRow->is_promotion);
            $this->assertTrue((bool) $tomorrowRow->is_school_term);
            $this->assertSame(21.0, (float) $tomorrowRow->temperature_c);
        }

        $this->assertSame('2026-10-22', DailySkuFeature::query()->where('sales_data_covered', true)->max('date'));
        $this->assertSame('2026-08-24', DailySkuFeature::query()->where('sales_data_covered', true)->min('date'));
    }

    public function test_daily_sales_and_context_replay_the_csv_shifted_to_end_today(): void
    {
        app(ForecastingDemoSeeder::class)->run();

        $csv = $this->csvRows('1L');
        $salesByDay = DB::table('sales_transactions')
            ->join('warehouse_stock', 'warehouse_stock.id', '=', 'sales_transactions.stock_id')
            ->join('products', 'products.id', '=', 'warehouse_stock.product_id')
            ->where('products.unit_type', '1L')
            ->selectRaw('DATE(sales_transactions.transaction_date) as sales_date, SUM(quantity) as units, COUNT(DISTINCT customer_id) as customers')
            ->groupBy('sales_date')
            ->orderBy('sales_date')
            ->get();

        $this->assertCount(60, $salesByDay);
        $this->assertSame(array_column($csv, 'units_sold'), $salesByDay->map(fn (object $day): int => (int) $day->units)->all());
        $this->assertSame([2, 3], $salesByDay->pluck('customers')->map(fn ($count): int => (int) $count)->unique()->sort()->values()->all());

        $today = DailySkuFeature::query()->where('product_sku', '1L')->whereDate('date', '2026-10-22')->sole();
        $last = end($csv);
        $this->assertSame($last['is_promotion'], (bool) $today->is_promotion);
        $this->assertSame($last['is_public_holiday'], (bool) $today->is_public_holiday);
        $this->assertSame($last['is_school_term'], (bool) $today->is_school_term);
        $this->assertSame(21.0, (float) $today->temperature_c);
        $this->assertSame(3, Customer::query()->where('customer_type', ForecastingDemoSeeder::DEMO_CUSTOMER_TYPE)->count());
    }

    public function test_replayed_sales_do_not_change_stock_levels(): void
    {
        app(ForecastingDemoSeeder::class)->run();

        $this->assertSame(0.0, (float) DB::table('warehouse_stock')->sum('current_level'));
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_rerunning_replaces_demo_rows_without_duplicates_or_stale_coverage(): void
    {
        $seeder = app(ForecastingDemoSeeder::class);
        $seeder->run();
        $salesCount = DB::table('sales_transactions')->count();
        $featureCount = DB::table('daily_sku_features')->count();

        $seeder->run();

        $this->assertSame($salesCount, DB::table('sales_transactions')->count());
        $this->assertSame($featureCount, DB::table('daily_sku_features')->count());
        $this->assertSame(3, Customer::query()->count());

        $this->travelTo('2026-10-23 09:00:00');
        $seeder->run();

        $this->assertSame($salesCount, DB::table('sales_transactions')->count());
        $this->assertFalse((bool) DailySkuFeature::query()->where('product_sku', '1L')->whereDate('date', '2026-08-24')->value('sales_data_covered'));
        $this->assertSame('2026-10-23', DailySkuFeature::query()->where('sales_data_covered', true)->max('date'));
    }

    public function test_purge_removes_demo_customers_sales_and_coverage(): void
    {
        $seeder = app(ForecastingDemoSeeder::class);
        $seeder->run();

        $seeder->purge();

        $this->assertDatabaseCount('sales_transactions', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertSame(0, DailySkuFeature::query()->where('sales_data_covered', true)->count());
        $this->assertDatabaseMissing('production_batches', ['notes' => ForecastingDemoSeeder::HISTORY_BATCH_NOTES]);
    }

    public function test_every_sku_can_be_forecast_for_tomorrow_after_seeding(): void
    {
        app(ForecastingDemoSeeder::class)->run();
        config(['services.ml_forecast.url' => 'http://ml-forecast.test']);
        Http::preventStrayRequests();
        Http::fake(['ml-forecast.test/predict' => Http::response(['predicted_units_sold' => 321, 'model_version' => 'test', 'rmse_score' => 4.5])]);

        foreach (self::SKUS as $sku) {
            $this->assertSame(ForecastStatus::Generated, app(MLForecastClient::class)->forecast($sku, '2026-10-23')->status);
        }

        Http::assertSentCount(5);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('restricted to local and testing');

        app(ForecastingDemoSeeder::class)->run();
    }

    public function test_it_refuses_to_guess_temperatures_without_climatology(): void
    {
        File::put("{$this->modelDirectory}/1L/manifest.json", json_encode(['model_version' => 'test']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no complete temperature_climatology');

        app(ForecastingDemoSeeder::class)->run();
    }

    /**
     * The last 60 CSV days for a SKU, oldest first.
     *
     * @return array<int, array{units_sold: int, is_promotion: bool, is_public_holiday: bool, is_school_term: bool}>
     */
    private function csvRows(string $sku): array
    {
        $handle = fopen(base_path('data/kenya_water_demand_daily.csv'), 'r');
        $headers = fgetcsv($handle);
        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            $row = array_combine($headers, $values);

            if ($row['product_sku'] === $sku) {
                $rows[$row['date']] = [
                    'units_sold' => (int) round((float) $row['units_sold']),
                    'is_promotion' => filter_var($row['is_promotion'], FILTER_VALIDATE_BOOL),
                    'is_public_holiday' => filter_var($row['is_public_holiday'], FILTER_VALIDATE_BOOL),
                    'is_school_term' => filter_var($row['is_school_term'], FILTER_VALIDATE_BOOL),
                ];
            }
        }

        fclose($handle);
        ksort($rows);

        return array_values(array_slice($rows, -60, preserve_keys: true));
    }
}
