<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_inventory_demo_and_replays_60_covered_days_onto_its_stock_lines(): void
    {
        $today = CarbonImmutable::parse('2026-10-04');
        $this->travelTo($today);
        $this->createAdministrator();

        app(DemoDataSeeder::class)->run();

        $this->assertDatabaseCount('products', 5);
        $this->assertDatabaseCount('sales_transactions', 750);
        $this->assertDatabaseCount('warehouse_stock', 6);
        $this->assertDatabaseCount('production_batches', 5);
        $this->assertDatabaseCount('stock_movements', 5);
        $this->assertDatabaseCount('leakage_reports', 4);
        $this->assertDatabaseCount('production_schedules', 4);
        $this->assertDatabaseCount('customers', 3);
        $this->assertSame(
            ['10L', '1L', '20L', '500ml', '5L'],
            Product::query()->orderBy('unit_type')->pluck('unit_type')->all(),
        );

        foreach (['500ml', '1L', '5L', '10L', '20L'] as $sku) {
            $coveredDates = DailySkuFeature::query()
                ->where('product_sku', $sku)
                ->where('sales_data_covered', true)
                ->orderBy('date')
                ->pluck('date')
                ->all();

            $this->assertCount(60, $coveredDates);
            $this->assertSame($today->subDays(59)->toDateString(), $coveredDates[0]);
            $this->assertSame($today->toDateString(), end($coveredDates));

            $tomorrowFeature = DailySkuFeature::query()
                ->where('product_sku', $sku)
                ->whereDate('date', $today->addDay()->toDateString())
                ->sole();

            $this->assertFalse($tomorrowFeature->sales_data_covered);
            $this->assertNotNull($tomorrowFeature->temperature_c);
        }

        $this->assertSame(0, DB::table('sales_transactions')
            ->whereNotIn('stock_id', DB::table('warehouse_stock')->select('id')->whereIn('batch_id', DB::table('production_batches')->select('id')->where('notes', 'like', 'Demo%')))
            ->count());

        $products = Product::query()->get()->keyBy('unit_type');
        $stockService = app(StockService::class);
        $this->assertSame('low', $stockService->statusForProduct($products['1L']));
        $this->assertSame('critical', $stockService->statusForProduct($products['20L']));
        $this->assertSame('ok', $stockService->statusForProduct($products['500ml']));

        $this->assertDatabaseHas('production_schedules', [
            'product_sku' => '1L',
            'scheduled_date' => $today->toDateString(),
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

        $this->assertDatabaseCount('customers', 3);
        $this->assertDatabaseCount('products', 5);
        $this->assertDatabaseCount('production_batches', 5);
        $this->assertDatabaseCount('warehouse_stock', 6);
        $this->assertDatabaseCount('stock_movements', 5);
        $this->assertDatabaseCount('leakage_reports', 4);
        $this->assertDatabaseCount('sales_transactions', 750);
        $this->assertDatabaseCount('daily_sku_features', 305);
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
            $predictions[$sku] = $client->predict($sku, $targetDate->addDay()->toDateString());
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
            'date' => '2026-10-04',
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

    private function writeModelManifests(): void
    {
        $directory = storage_path('framework/testing/demo-data-manifests');

        foreach (['500ml', '1L', '5L', '10L', '20L'] as $sku) {
            File::ensureDirectoryExists("{$directory}/{$sku}");
            File::put("{$directory}/{$sku}/manifest.json", json_encode([
                'temperature_climatology' => array_fill_keys(array_map('strval', range(1, 12)), 22.5),
            ]));
        }

        config(['services.ml_forecast.model_dir' => $directory]);
    }

    private function createAdministrator(): User
    {
        $this->writeModelManifests();
        $this->seed(RoleSeeder::class);
        $role = Role::query()->where('slug', 'administrator')->firstOrFail();

        return User::factory()->create(['role_id' => $role->id]);
    }
}
