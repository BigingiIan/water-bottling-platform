<?php

namespace Tests\Feature;

use App\Livewire\ProductionScheduleTable;
use App\Models\DailySkuFeature;
use App\Models\Product;
use App\Models\ProductionLine;
use App\Models\ProductionLog;
use App\Models\ProductionSchedule;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionScheduleTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            ModuleSeeder::class,
            RoleModulePermissionSeeder::class,
        ]);
        config(['services.ml_forecast.url' => 'http://ml-forecast.test']);
        Http::preventStrayRequests();

        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $this->actingAs(User::factory()->create(['role_id' => $administrator->id]));
    }

    public function test_table_reads_schedules_and_computes_progress_from_logs(): void
    {
        $line = ProductionLine::create(['name' => 'Line 01', 'status' => 'active']);
        $operator = User::factory()->create();
        $date = today()->toDateString();

        $schedule = ProductionSchedule::create([
            'product_sku' => '1L',
            'production_line_id' => $line->id,
            'scheduled_date' => $date,
            'scheduled_time' => '10:30:00',
            'target_units' => 2500,
            'status' => 'active',
        ]);
        ProductionLog::create([
            'production_schedule_id' => $schedule->id,
            'operator_id' => $operator->id,
            'units_logged' => 1320,
            'logged_at' => now(),
            'quality_status' => 'passed',
        ]);
        ProductionLog::create([
            'production_schedule_id' => $schedule->id,
            'operator_id' => $operator->id,
            'units_logged' => 1000,
            'logged_at' => now(),
            'quality_status' => 'passed',
        ]);

        Livewire::test(ProductionScheduleTable::class)
            ->assertSee('1L')
            ->assertSee('Line 01')
            ->assertSee('2,500')
            ->assertSee('2,320')
            ->assertSee('93%');
    }

    public function test_table_filters_by_line_sku_and_status(): void
    {
        $lineOne = ProductionLine::create(['name' => 'Line 01', 'status' => 'active']);
        $lineTwo = ProductionLine::create(['name' => 'Line 02', 'status' => 'active']);
        $date = today()->toDateString();

        ProductionSchedule::create([
            'product_sku' => '500ml',
            'production_line_id' => $lineOne->id,
            'scheduled_date' => $date,
            'scheduled_time' => '08:00:00',
            'target_units' => 3000,
            'status' => 'complete',
        ]);
        ProductionSchedule::create([
            'product_sku' => '1L',
            'production_line_id' => $lineTwo->id,
            'scheduled_date' => $date,
            'scheduled_time' => '10:30:00',
            'target_units' => 2500,
            'status' => 'active',
        ]);

        Livewire::test(ProductionScheduleTable::class)
            ->assertSee('08:00')
            ->assertSee('10:30')
            ->set('lineFilter', (string) $lineTwo->id)
            ->assertSee('10:30')
            ->assertDontSee('08:00')
            ->set('lineFilter', '')
            ->set('skuFilter', '500ml')
            ->assertSee('08:00')
            ->assertDontSee('10:30')
            ->set('skuFilter', '')
            ->set('statusFilter', 'complete')
            ->assertSee('08:00')
            ->assertDontSee('10:30');
    }

    public function test_table_only_shows_selected_date_and_switches_dates(): void
    {
        $line = ProductionLine::create(['name' => 'Line 01', 'status' => 'active']);
        $today = today()->toDateString();
        $tomorrow = today()->addDay()->toDateString();

        ProductionSchedule::create([
            'product_sku' => '5L',
            'production_line_id' => $line->id,
            'scheduled_date' => $today,
            'scheduled_time' => '13:00:00',
            'target_units' => 1500,
            'status' => 'queued',
        ]);
        ProductionSchedule::create([
            'product_sku' => '20L',
            'production_line_id' => $line->id,
            'scheduled_date' => $tomorrow,
            'scheduled_time' => '15:30:00',
            'target_units' => 800,
            'status' => 'queued',
        ]);

        Livewire::test(ProductionScheduleTable::class)
            ->assertSee('5L')
            ->assertDontSee('20L')
            ->set('selectedDate', $tomorrow)
            ->assertSee('20L')
            ->assertDontSee('5L');
    }

    public function test_schedule_route_renders_livewire_table_with_real_data(): void
    {
        $line = ProductionLine::create(['name' => 'Line 01', 'status' => 'active']);
        ProductionSchedule::create([
            'product_sku' => '500ml',
            'production_line_id' => $line->id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => '08:00:00',
            'target_units' => 3000,
            'status' => 'complete',
        ]);

        $this->get('/production/schedule')
            ->assertOk()
            ->assertSee('Production Schedule')
            ->assertSee('500ml')
            ->assertSee('wire:id', false);
    }

    public function test_schedule_page_shows_unavailable_instead_of_mock_forecast_values(): void
    {
        $this->createSchedule('1L', '10:30:00', 2500, 'active');

        $this->get('/production/schedule')
            ->assertOk()
            ->assertSee('Forecast unavailable')
            ->assertDontSee('Behind ML forecast')
            ->assertDontSee('140 units');
    }

    public function test_users_without_production_access_are_forbidden_from_schedule(): void
    {
        $inventoryStaff = Role::query()->where('slug', 'inventory-staff')->firstOrFail();
        $this->actingAs(User::factory()->create(['role_id' => $inventoryStaff->id]));

        $this->get('/production/schedule')->assertForbidden();
    }

    private function provideForecastFeatures(string $sku, float $demand): void
    {
        Product::create(['name' => 'Aqua '.$sku, 'unit_type' => $sku]);

        for ($offset = 14; $offset >= 0; $offset--) {
            DailySkuFeature::create([
                'product_sku' => $sku,
                'date' => CarbonImmutable::today()->subDays($offset)->toDateString(),
                'is_promotion' => false,
                'is_public_holiday' => false,
                'is_school_term' => true,
                'sales_data_covered' => true,
                'temperature_c' => 22.5,
            ]);
        }

        Http::fake([
            'ml-forecast.test/predict' => Http::response([
                'predicted_units_sold' => $demand,
                'model_version' => '2026-10-01',
                'rmse_score' => 12.3456,
            ]),
        ]);
    }

    private function createSchedule(string $sku, string $time, int $target, string $status): ProductionSchedule
    {
        $line = ProductionLine::firstOrCreate(['name' => 'Line 01'], ['status' => 'active']);

        return ProductionSchedule::create([
            'product_sku' => $sku,
            'production_line_id' => $line->id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => $time,
            'target_units' => $target,
            'status' => $status,
        ]);
    }

    public function test_strip_flags_schedule_meaningfully_below_forecast(): void
    {
        $this->createSchedule('1L', '10:30:00', 2500, 'active');
        $this->provideForecastFeatures('1L', 2640);

        Livewire::test(ProductionScheduleTable::class)
            ->assertSee('ML Demand Forecast')
            ->assertSee('2,640 units')
            ->assertSee('below forecast');
    }

    public function test_strip_shows_covered_state_when_target_meets_forecast(): void
    {
        $this->createSchedule('1L', '10:30:00', 3000, 'active');
        $this->provideForecastFeatures('1L', 2640);

        Livewire::test(ProductionScheduleTable::class)
            ->assertSee('ML Demand Forecast')
            ->assertSee('Covers forecast')
            ->assertDontSee('below forecast');
    }

    public function test_strip_tolerates_shortfall_within_five_percent(): void
    {
        $this->createSchedule('1L', '10:30:00', 2400, 'active');
        $this->provideForecastFeatures('1L', 2500);

        Livewire::test(ProductionScheduleTable::class)
            ->assertSee('Covers forecast')
            ->assertDontSee('below forecast');
    }

    public function test_strip_uses_prediction_and_sums_split_targets(): void
    {
        $lineTwo = ProductionLine::create(['name' => 'Line 02', 'status' => 'active']);
        $this->createSchedule('1L', '10:30:00', 1500, 'active');
        ProductionSchedule::create([
            'product_sku' => '1L',
            'production_line_id' => $lineTwo->id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => '13:00:00',
            'target_units' => 1000,
            'status' => 'queued',
        ]);
        $this->provideForecastFeatures('1L', 2640);

        Livewire::test(ProductionScheduleTable::class)
            ->assertSee('ML Demand Forecast')
            ->assertSee('2,500')
            ->assertSee('below forecast');
    }

    public function test_strip_is_hidden_without_active_run_and_unavailable_without_features(): void
    {
        $this->createSchedule('1L', '13:00:00', 1500, 'queued');

        Livewire::test(ProductionScheduleTable::class)
            ->assertDontSee('ML Demand Forecast');

        $this->createSchedule('5L', '10:30:00', 1500, 'active');

        Livewire::test(ProductionScheduleTable::class)
            ->assertSee('Forecast unavailable')
            ->assertSee('14 consecutive days');
    }
}
