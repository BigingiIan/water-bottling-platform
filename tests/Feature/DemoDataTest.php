<?php

namespace Tests\Feature;

use App\Services\Demo\DemoCatalog;
use App\Services\Demo\DemoDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    private const SKUS = ['10L', '1L', '20L', '500ml', '5L'];

    private string $modelDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->modelDirectory = storage_path('framework/testing/demo-data-manifests');

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

        config([
            'services.ml_forecast.model_dir' => $this->modelDirectory,
            'services.ml_forecast.url' => 'http://ml-forecast.test',
        ]);
        Http::fake([
            'ml-forecast.test/climatology' => Http::response([], 503),
            'ml-forecast.test/predict' => Http::response(['predicted_units_sold' => 321, 'model_version' => 'test', 'rmse_score' => 4.5]),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->modelDirectory);

        parent::tearDown();
    }

    public function test_thirty_days_of_demo_data_pass_every_verification_check(): void
    {
        $this->artisan('demo:seed', ['--days' => 30])
            ->expectsOutputToContain('Every demo account signs in with the password: '.DemoCatalog::PASSWORD)
            ->assertSuccessful();

        $this->artisan('demo:verify')
            ->expectsOutputToContain('All 9 checks passed.')
            ->assertSuccessful();

        // History runs to yesterday, today is a closed day, and the evening's open orders come after the close.
        $this->assertSame('2026-09-22', DB::table('orders')->min('order_date'));
        $this->assertSame(31, DB::table('daily_sku_features')->where('product_sku', '500ml')->where('sales_data_covered', true)->count());
        $this->assertSame(5, DB::table('orders')->whereIn('status', ['draft', 'confirmed'])->whereDate('order_date', '2026-10-22')->count());
        $this->assertSame(5, DB::table('forecast_records')->whereDate('forecast_date', '2026-10-23')->count());
        $this->assertSame(5, DB::table('production_schedules')->whereDate('scheduled_date', '2026-10-23')->where('status', 'queued')->count());

        $this->assertSame(10, DB::table('users')->where('email', 'like', '%@'.DemoCatalog::EMAIL_DOMAIN)->count());
        $this->assertSame(40, DB::table('customers')->count());
        $this->assertSame(15, DB::table('equipment')->count());
        $this->assertSame(1, DB::table('equipment')->where('status', 'out_of_service')->count());
        $this->assertSame(
            ['acknowledged', 'cancelled', 'declined', 'done', 'in_progress', 'open'],
            DB::table('requests')->distinct()->orderBy('status')->pluck('status')->all(),
        );
        $this->assertSame(5, DB::table('leakage_reports')->where('finding', 'process_loss')->where('detected_by', 'rule')->where('severity', 'review')->count());
        $this->assertSame(2, DB::table('leakage_reports')->where('severity', 'investigate')->count());
        $this->assertTrue(DB::table('leakage_reports')->where('detected_by', 'anomaly')->exists());
        $this->assertSame(['bank_transfer', 'cash', 'mpesa'], DB::table('payments')->distinct()->orderBy('method')->pluck('method')->all());
    }

    public function test_the_same_day_always_produces_the_same_data(): void
    {
        $this->artisan('demo:seed', ['--days' => 14, '--customers' => 12])->assertSuccessful();
        $first = $this->fingerprint();

        $this->artisan('demo:seed', ['--days' => 14, '--customers' => 12])->assertSuccessful();

        $this->assertSame($first, $this->fingerprint());
        $this->assertSame(12, DB::table('customers')->count());
    }

    public function test_demo_clear_removes_the_demo_rows_and_keeps_the_base_data(): void
    {
        $this->artisan('demo:seed', ['--days' => 14])->assertSuccessful();

        $this->artisan('demo:clear')->assertSuccessful();

        foreach (['users', 'customers', 'orders', 'invoices', 'payments', 'stock_movements', 'warehouse_stock', 'production_batches', 'equipment', 'work_orders', 'requests', 'notifications', 'forecast_records', 'demo_data_ranges'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} still has rows");
        }

        $this->assertSame(0, DB::table('daily_sku_features')->where('sales_data_covered', true)->count());
        $this->assertSame(5, DB::table('products')->count());
        $this->assertSame(5, DB::table('roles')->count());
        $this->assertTrue(DB::table('public_holidays')->exists());
        $this->artisan('demo:clear')->expectsOutputToContain('There is no demo data to remove.')->assertSuccessful();
    }

    public function test_it_refuses_to_mix_with_real_orders_or_stock(): void
    {
        $this->artisan('demo:seed', ['--days' => 14])->assertSuccessful();
        DB::table('demo_data_ranges')->delete();

        $this->artisan('demo:seed', ['--days' => 14])
            ->expectsOutputToContain('Run php artisan demo:seed --fresh')
            ->assertFailed();
    }

    public function test_the_demo_commands_refuse_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('demo:seed')->expectsOutputToContain('only runs in the local and testing environments')->assertFailed();
        $this->artisan('demo:clear')->expectsOutputToContain('only runs in the local and testing environments')->assertFailed();
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_it_needs_at_least_two_weeks_of_history(): void
    {
        $this->artisan('demo:seed', ['--days' => DemoDataBuilder::MIN_DAYS - 1])->assertExitCode(2);
    }

    /**
     * @return array<string, mixed>
     */
    private function fingerprint(): array
    {
        return [
            'sales' => DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->join('customers', 'customers.id', '=', 'orders.customer_id')
                ->groupBy('customers.name')->orderBy('customers.name')
                ->selectRaw('customers.name, SUM(order_items.quantity) AS units')->pluck('units', 'name')->map(fn (mixed $units): int => (int) $units)->all(),
            'payments' => DB::table('payments')->orderBy('reference')->pluck('reference')->all(),
            'intake' => DB::table('production_batches')->orderBy('batch_date')->orderBy('raw_water_volume')->pluck('raw_water_volume')->map(fn (mixed $volume): string => (string) $volume)->all(),
            'counts' => DB::table('stock_counts')->orderBy('created_at')->orderBy('product_id')->pluck('shrinkage_units')->all(),
        ];
    }
}
