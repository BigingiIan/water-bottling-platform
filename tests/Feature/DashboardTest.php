<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\ForecastRecord;
use App\Models\LeakageReport;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionLine;
use App\Models\ProductionLog;
use App\Models\ProductionSchedule;
use App\Models\Role;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Notifications\SystemNotification;
use App\Services\SettingsService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->seed([
            RoleSeeder::class,
            ModuleSeeder::class,
            RoleModulePermissionSeeder::class,
        ]);
        Http::preventStrayRequests();
    }

    public function test_administrator_sees_every_card_with_helpful_empty_states(): void
    {
        $this->actingAs($this->userWithRole('administrator', ['name' => 'Amina']));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Welcome back, Amina')
            ->assertSee("Today's output")
            ->assertSee('No runs scheduled today.')
            ->assertSee('Create a schedule')
            ->assertSee('Open runs')
            ->assertSee('No open runs today. Every scheduled run is complete.')
            ->assertSee('Stock alerts')
            ->assertSee('All products are above their reorder thresholds.')
            ->assertSee('Leakage review')
            ->assertSee('No open leakage reports need review.')
            ->assertSee("Tomorrow's forecast vs stock cover")
            ->assertSee('No products to forecast yet.')
            ->assertSee('Latest notifications')
            ->assertSee("You're all caught up.", false)
            ->assertSee('href="'.route('production.schedule').'"', false)
            ->assertSee('href="'.route('production.runs').'"', false)
            ->assertSee('href="'.route('inventory.overview', ['tab' => 'leakage']).'"', false)
            ->assertSee('href="'.route('forecasting.index').'"', false)
            ->assertSee('href="'.route('notifications.index').'"', false);
    }

    public function test_cards_follow_the_users_module_access(): void
    {
        $this->actingAs($this->userWithRole('inventory-staff'));

        Livewire::test(Dashboard::class)
            ->assertSee('Stock alerts')
            ->assertSee('Leakage review')
            ->assertDontSee("Today's output")
            ->assertDontSee('Open runs')
            ->assertDontSee("Tomorrow's forecast vs stock cover")
            ->assertSee('Latest notifications');

        $this->actingAs($this->userWithRole('production-staff'));

        Livewire::test(Dashboard::class)
            ->assertSee("Today's output")
            ->assertDontSee('Stock alerts');
    }

    public function test_role_without_module_cards_only_sees_notifications_and_guidance(): void
    {
        $maintenance = $this->userWithRole('maintenance-staff');
        $maintenance->notify($this->notification('Pump service due', 'test:pump'));
        $this->actingAs($maintenance);

        Livewire::test(Dashboard::class)
            ->assertSee('Pump service due')
            ->assertSee('Need something from another team?')
            ->assertSee('Ask for access')
            ->assertSeeHtml('name="module" value="inventory"')
            ->assertSeeHtml('name="module" value="production"')
            ->assertSeeHtml('name="module" value="orders"')
            ->assertDontSee('Needs your attention')
            ->assertDontSee('Stock alerts')
            ->assertDontSee("Today's output");
    }

    public function test_cards_summarise_todays_operations(): void
    {
        $administrator = $this->userWithRole('administrator');
        $this->actingAs($administrator);
        $line = ProductionLine::query()->create(['name' => 'Line 01', 'status' => 'active']);
        $active = $this->createSchedule($line, '500ml', '08:00:00', 1000, 'active');
        $this->createSchedule($line, '1L', '13:00:00', 500, 'complete');
        ProductionLog::query()->create([
            'production_schedule_id' => $active->id,
            'operator_id' => $administrator->id,
            'units_logged' => 600,
            'logged_at' => now(),
            'quality_status' => 'passed',
        ]);
        $empty = $this->createProductWithStock('5L', threshold: 100, level: 0);
        $healthy = $this->createProductWithStock('10L', threshold: 100, level: 400);
        LeakageReport::query()->create([
            'batch_id' => $healthy['batch']->id,
            'report_date' => today()->toDateString(),
            'expected_volume' => 100,
            'actual_volume' => 90,
            'discrepancy' => -10,
        ]);
        ForecastRecord::query()->create([
            'product_id' => $healthy['product']->id,
            'forecast_date' => '2026-10-23',
            'predicted_demand' => 200,
            'horizon_days' => 1,
            'model_version' => 'v1',
            'rmse_score' => 3,
            'generated_at' => now(),
        ]);
        foreach (range(1, 6) as $index) {
            $this->travel(1)->minutes();
            $administrator->notify($this->notification("Alert {$index}", "test:{$index}"));
        }

        Livewire::test(Dashboard::class)
            ->assertSee('600')
            ->assertSee('/ 1,500 units')
            ->assertSee('40% of target across 2 runs')
            ->assertSeeText('500ml · Line 01 · 08:00')
            ->assertDontSeeText('1L · Line 01 · 13:00')
            ->assertSee('1 product')
            ->assertSee('Out of stock')
            ->assertSee('1 report')
            ->assertSee('2 days')
            ->assertSee('No forecast')
            ->assertSee('Alert 6')
            ->assertSee('Alert 2')
            ->assertDontSee('Alert 1<', false);

        $this->assertSame(0, $empty['product']->forecastRecords()->count());
    }

    public function test_forecast_card_respects_the_settings_toggle(): void
    {
        $administrator = $this->userWithRole('administrator');
        app(SettingsService::class)->set('forecast.enabled', false, $administrator);
        $this->actingAs($administrator);

        Livewire::test(Dashboard::class)->assertSee('ML suggestions are turned off in Settings.');
    }

    public function test_query_count_does_not_grow_with_products_and_runs(): void
    {
        $administrator = $this->userWithRole('administrator');
        $this->actingAs($administrator);
        $line = ProductionLine::query()->create(['name' => 'Line 01', 'status' => 'active']);
        $this->createSchedule($line, '500ml', '08:00:00', 1000, 'queued');
        $this->createProductWithStock('500ml', threshold: 100, level: 10);
        Livewire::test(Dashboard::class);

        $fewQueries = $this->countQueries(fn () => Livewire::test(Dashboard::class));

        foreach (['1L', '5L', '10L', '20L'] as $index => $sku) {
            $this->createSchedule($line, $sku, sprintf('%02d:00:00', 9 + $index), 500, 'active');
            $this->createProductWithStock($sku, threshold: 100, level: 5);
        }

        $manyQueries = $this->countQueries(fn () => Livewire::test(Dashboard::class));

        $this->assertSame($fewQueries, $manyQueries);
    }

    private function createSchedule(ProductionLine $line, string $sku, string $time, int $target, string $status): ProductionSchedule
    {
        return ProductionSchedule::query()->create([
            'product_sku' => $sku,
            'production_line_id' => $line->id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => $time,
            'target_units' => $target,
            'status' => $status,
        ]);
    }

    /**
     * @return array{product: Product, batch: ProductionBatch}
     */
    private function createProductWithStock(string $sku, int $threshold, int $level): array
    {
        $product = Product::query()->create(['name' => 'Bottled Water '.$sku, 'unit_type' => $sku, 'reorder_threshold' => $threshold]);
        $batch = ProductionBatch::query()->create([
            'user_id' => auth()->id(),
            'batch_date' => today()->toDateString(),
            'raw_water_volume' => 100,
            'bottled_volume' => 100,
            'status' => 'completed',
        ]);
        WarehouseStock::query()->create(['batch_id' => $batch->id, 'product_id' => $product->id, 'current_level' => $level]);

        return ['product' => $product, 'batch' => $batch];
    }

    private function notification(string $title, string $key): SystemNotification
    {
        return new SystemNotification([
            'type' => 'test',
            'title' => $title,
            'body' => 'Body',
            'url' => '/notifications',
            'severity' => 'info',
            'key' => $key,
        ]);
    }

    private function countQueries(callable $callback): int
    {
        // Dashboard figures are memoised per request; each count starts like a fresh request.
        $this->app->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(string $roleSlug, array $attributes = []): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->valueOrFail('id'),
            ...$attributes,
        ]);
    }
}
