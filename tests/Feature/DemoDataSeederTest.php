<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DailySkuFeature;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\MLForecastClient;
use App\Support\LeakageReportFlag;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_30_covered_days_and_complete_target_features_for_each_sku(): void
    {
        $targetDate = CarbonImmutable::parse('2026-10-04');
        $this->travelTo($targetDate);
        $this->createAdministrator();

        app(DemoDataSeeder::class)->run();

        $this->assertDatabaseCount('products', 5);
        $this->assertDatabaseCount('sales_transactions', 150);
        $this->assertDatabaseCount('warehouse_stock', 6);
        $this->assertDatabaseCount('production_batches', 5);
        $this->assertDatabaseCount('stock_movements', 5);
        $this->assertDatabaseCount('leakage_reports', 4);
        $this->assertDatabaseCount('production_schedules', 4);
        $this->assertSame(
            ['10L', '1L', '20L', '500ml', '5L'],
            Product::query()->orderBy('unit_type')->pluck('unit_type')->all(),
        );

        foreach (['500ml', '1L', '5L', '10L', '20L'] as $sku) {
            $this->assertSame(31, DailySkuFeature::query()->where('product_sku', $sku)->count());
            $coveredFeatures = DailySkuFeature::query()
                ->where('product_sku', $sku)
                ->where('sales_data_covered', true)
                ->orderBy('date')
                ->get();

            $this->assertCount(30, $coveredFeatures);
            $this->assertSame(
                collect(range(30, 1))
                    ->map(fn (int $offset): string => $targetDate->subDays($offset)->toDateString())
                    ->all(),
                $coveredFeatures->pluck('date')->all(),
            );

            $targetFeature = DailySkuFeature::query()
                ->where('product_sku', $sku)
                ->whereDate('date', $targetDate->toDateString())
                ->firstOrFail();

            $this->assertFalse($targetFeature->sales_data_covered);
            $this->assertNotNull($targetFeature->is_promotion);
            $this->assertNotNull($targetFeature->is_public_holiday);
            $this->assertNotNull($targetFeature->is_school_term);
            $this->assertNotNull($targetFeature->temperature_c);
            $this->assertSame(
                30,
                DB::table('sales_transactions')
                    ->join('warehouse_stock', 'warehouse_stock.id', '=', 'sales_transactions.stock_id')
                    ->join('products', 'products.id', '=', 'warehouse_stock.product_id')
                    ->where('products.unit_type', $sku)
                    ->where('sales_transactions.customer_id', Customer::query()->where('name', 'DEMO — synthetic data')->value('id'))
                    ->distinct()
                    ->count(DB::raw('DATE(sales_transactions.transaction_date)')),
            );
        }

        $mappedHistorySale = DB::table('sales_transactions')
            ->join('warehouse_stock', 'warehouse_stock.id', '=', 'sales_transactions.stock_id')
            ->join('products', 'products.id', '=', 'warehouse_stock.product_id')
            ->where('products.unit_type', '1L')
            ->whereDate('sales_transactions.transaction_date', $targetDate->subDay()->toDateString())
            ->first(['sales_transactions.quantity', 'sales_transactions.unit_price', 'sales_transactions.total_value']);

        $this->assertNotNull($mappedHistorySale);
        $this->assertSame(901.0, (float) $mappedHistorySale->quantity);
        $this->assertSame(45.0, (float) $mappedHistorySale->unit_price);
        $this->assertSame(40545.0, (float) $mappedHistorySale->total_value);
        $this->assertFalse(DailySkuFeature::query()
            ->where('product_sku', '1L')
            ->whereDate('date', $targetDate->subDay()->toDateString())
            ->value('is_promotion'));
        $this->assertSame(24.1, (float) DailySkuFeature::query()
            ->where('product_sku', '1L')
            ->whereDate('date', $targetDate->subDay()->toDateString())
            ->value('temperature_c'));

        $products = Product::query()->get()->keyBy('unit_type');
        $stockService = app(StockService::class);
        $this->assertSame('low', $stockService->statusForProduct($products['1L']));
        $this->assertSame('critical', $stockService->statusForProduct($products['20L']));
        $this->assertSame('ok', $stockService->statusForProduct($products['500ml']));

        $this->assertSame(0, DB::table('sales_transactions')
            ->whereDate('transaction_date', $targetDate->toDateString())
            ->count());
        $this->assertDatabaseHas('production_schedules', [
            'product_sku' => '1L',
            'scheduled_date' => $targetDate->toDateString(),
            'scheduled_time' => '10:30:00',
            'status' => 'active',
        ]);
    }

    public function test_rerunning_it_keeps_inventory_demo_records_idempotent_and_statuses_visible(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04'));
        $this->createAdministrator();
        $this->seed([ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        $seeder = app(DemoDataSeeder::class);

        $seeder->run();
        $seeder->run();

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('products', 5);
        $this->assertDatabaseCount('production_batches', 5);
        $this->assertDatabaseCount('warehouse_stock', 6);
        $this->assertDatabaseCount('stock_movements', 5);
        $this->assertDatabaseCount('leakage_reports', 4);
        $this->assertDatabaseCount('sales_transactions', 150);
        $this->assertDatabaseCount('daily_sku_features', 155);
        $this->assertDatabaseCount('production_schedules', 4);

        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $this->actingAs(User::query()->where('role_id', $administrator->id)->firstOrFail())
            ->get(route('inventory.overview'))
            ->assertOk()
            ->assertSee('6 stock lines')
            ->assertSee('OK')
            ->assertSee('Low')
            ->assertSee('Critical');

        $this->assertSame(9600.0, (float) DB::table('warehouse_stock')
            ->join('products', 'products.id', '=', 'warehouse_stock.product_id')
            ->where('products.unit_type', '500ml')
            ->sum('current_level'));
        $this->assertSame(2150.0, (float) DB::table('warehouse_stock')
            ->join('products', 'products.id', '=', 'warehouse_stock.product_id')
            ->where('products.unit_type', '1L')
            ->sum('current_level'));
        $stockLines = DB::table('warehouse_stock')
            ->join('products', 'products.id', '=', 'warehouse_stock.product_id')
            ->whereIn('warehouse_stock.batch_id', DB::table('production_batches')
                ->select('id')
                ->where('notes', 'like', 'Demo%'))
            ->orderBy('products.unit_type')
            ->orderBy('warehouse_stock.warehouse_location')
            ->get([
                'products.unit_type',
                'warehouse_stock.warehouse_location',
                'warehouse_stock.current_level',
                'warehouse_stock.minimum_threshold',
            ])
            ->map(fn (object $line): array => [
                $line->unit_type,
                $line->warehouse_location,
                (int) $line->current_level,
                (int) $line->minimum_threshold,
            ])
            ->all();
        $this->assertSame([
            ['10L', 'Bay C2', 610, 500],
            ['1L', 'Bay B1', 2150, 2500],
            ['20L', 'Bay D1', 90, 250],
            ['500ml', 'Bay A1', 6400, 1500],
            ['500ml', 'Bay A2', 3200, 1500],
            ['5L', 'Bay C1', 3450, 1500],
        ], $stockLines);

        $seededFlags = DB::table('leakage_reports')
            ->join('production_batches', 'production_batches.id', '=', 'leakage_reports.batch_id')
            ->whereIn('production_batches.notes', [
                'Demo data: synthetic demand forecasting seed.',
                'Demo inventory batch B-0934',
                'Demo inventory batch B-0932',
                'Demo inventory batch B-0930',
            ])
            ->get(['expected_volume', 'actual_volume'])
            ->map(fn (object $report): string => LeakageReportFlag::fromVolumes(
                (float) $report->expected_volume,
                (float) $report->actual_volume,
            ))
            ->sort()
            ->values()
            ->all();
        sort($seededFlags);
        $this->assertSame(['Investigate', 'Normal', 'Normal', 'Review'], $seededFlags);
    }

    public function test_each_sku_builds_a_forecast_request_after_seeding(): void
    {
        $targetDate = CarbonImmutable::parse('2026-10-04');
        $this->travelTo($targetDate);
        $this->createAdministrator();
        app(DemoDataSeeder::class)->run();
        config(['services.ml_forecast.url' => 'http://ml-forecast.test']);
        Http::preventStrayRequests();
        Http::fake([
            'ml-forecast.test/predict' => Http::response([
                'predicted_units_sold' => 100,
                'model_version' => '2026-10-01',
                'rmse_score' => 1.25,
            ]),
        ]);

        $client = app(MLForecastClient::class);
        $predictions = [];

        foreach (['500ml', '1L', '5L', '10L', '20L'] as $sku) {
            $predictions[$sku] = $client->predict($sku, $targetDate->toDateString());
        }

        $this->assertSame([
            '500ml' => 100.0,
            '1L' => 100.0,
            '5L' => 100.0,
            '10L' => 100.0,
            '20L' => 100.0,
        ], $predictions);
        Http::assertSentCount(5);
        $this->assertDatabaseCount('forecast_records', 5);
    }

    public function test_purge_removes_the_demo_customer_sales_and_batch(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04'));
        $this->createAdministrator();
        $seeder = app(DemoDataSeeder::class);
        $seeder->run();

        $seeder->purge();

        $this->assertDatabaseCount('sales_transactions', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('production_batches', 0);
        $this->assertDatabaseCount('warehouse_stock', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('leakage_reports', 0);
        $this->assertDatabaseMissing('daily_sku_features', [
            'product_sku' => '1L',
            'date' => '2026-10-03',
            'sales_data_covered' => true,
        ]);
    }

    public function test_it_refuses_to_seed_outside_local_or_testing_environments(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('restricted to local and testing');

        app(DemoDataSeeder::class)->run();
    }

    private function createAdministrator(): User
    {
        $this->seed(RoleSeeder::class);
        $role = Role::query()->where('slug', 'administrator')->firstOrFail();

        return User::factory()->create(['role_id' => $role->id]);
    }
}
