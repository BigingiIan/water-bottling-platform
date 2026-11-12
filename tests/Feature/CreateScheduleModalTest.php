<?php

namespace Tests\Feature;

use App\Livewire\CreateScheduleModal;
use App\Models\DailySkuFeature;
use App\Models\Module;
use App\Models\Product;
use App\Models\ProductionLine;
use App\Models\ProductionSchedule;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CreateScheduleModalTest extends TestCase
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

    private function createLine(string $name = 'Line 01'): ProductionLine
    {
        return ProductionLine::create(['name' => $name, 'status' => 'active']);
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

    public function test_full_access_can_create_queued_run(): void
    {
        $line = $this->createLine();
        Product::create(['name' => 'Aqua 1L', 'unit_type' => '1L']);
        $date = today()->addDay()->toDateString();

        Livewire::test(CreateScheduleModal::class)
            ->call('openModal')
            ->set('scheduledDate', $date)
            ->set('scheduledTime', '16:00')
            ->set('productSku', '1L')
            ->set('productionLineId', $line->id)
            ->set('targetUnits', '800')
            ->call('saveSchedule')
            ->assertHasNoErrors()
            ->assertDispatched('schedule-created');

        $this->assertDatabaseHas('production_schedules', [
            'product_sku' => '1L',
            'production_line_id' => $line->id,
            'target_units' => 800,
            'status' => 'queued',
        ]);
        $this->assertSame($date, ProductionSchedule::firstOrFail()->scheduled_date->toDateString());
    }

    public function test_view_access_cannot_open_or_save(): void
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $production = Module::query()->where('slug', 'production')->firstOrFail();
        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', $production->id)
            ->update(['access_level' => 'view']);

        $this->actingAs(User::factory()->create(['role_id' => $productionManager->id]));

        Livewire::test(CreateScheduleModal::class)
            ->call('openModal')
            ->assertStatus(403);

        Livewire::test(CreateScheduleModal::class)
            ->set('scheduledDate', today()->toDateString())
            ->set('scheduledTime', '16:00')
            ->set('productSku', '1L')
            ->set('productionLineId', 1)
            ->set('targetUnits', '800')
            ->call('saveSchedule')
            ->assertStatus(403);

        $this->assertSame(0, ProductionSchedule::count());
    }

    public function test_save_validates_past_date_target_and_duplicate_slot(): void
    {
        $line = $this->createLine();
        Product::create(['name' => 'Aqua 1L', 'unit_type' => '1L']);
        ProductionSchedule::create([
            'product_sku' => '1L',
            'production_line_id' => $line->id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => '10:30:00',
            'target_units' => 2500,
            'status' => 'queued',
        ]);

        $component = Livewire::test(CreateScheduleModal::class)
            ->call('openModal')
            ->set('scheduledDate', today()->subDay()->toDateString())
            ->set('scheduledTime', '10:30')
            ->set('productSku', '1L')
            ->set('productionLineId', $line->id)
            ->set('targetUnits', '500')
            ->call('saveSchedule')
            ->assertHasErrors(['scheduledDate']);

        $component
            ->set('scheduledDate', today()->toDateString())
            ->call('saveSchedule')
            ->assertHasErrors(['productionLineId']);

        $component
            ->set('scheduledTime', '16:00')
            ->set('targetUnits', '0')
            ->call('saveSchedule')
            ->assertHasErrors(['targetUnits']);

        $this->assertSame(1, ProductionSchedule::count());
    }

    public function test_forecast_suggestion_can_be_applied(): void
    {
        $line = $this->createLine();
        $this->provideForecastFeatures('1L', 2640);

        Livewire::test(CreateScheduleModal::class)
            ->call('openModal')
            ->set('scheduledDate', today()->toDateString())
            ->set('productSku', '1L')
            ->call('requestForecast')
            ->assertSee('ML suggestion')
            ->assertSee('2,640')
            ->call('applyForecast')
            ->assertSet('targetUnits', '2640')
            ->set('scheduledTime', '16:00')
            ->set('productionLineId', $line->id)
            ->call('saveSchedule')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('production_schedules', [
            'product_sku' => '1L',
            'target_units' => 2640,
            'status' => 'queued',
        ]);
    }

    public function test_unavailable_forecast_still_allows_creation(): void
    {
        $line = $this->createLine();
        Product::create(['name' => 'Aqua 5L', 'unit_type' => '5L']);

        Livewire::test(CreateScheduleModal::class)
            ->call('openModal')
            ->set('scheduledDate', today()->toDateString())
            ->set('productSku', '5L')
            ->call('requestForecast')
            ->assertSee('Forecast unavailable')
            ->set('scheduledTime', '16:00')
            ->set('productionLineId', $line->id)
            ->set('targetUnits', '1500')
            ->call('saveSchedule')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('production_schedules', [
            'product_sku' => '5L',
            'target_units' => 1500,
            'status' => 'queued',
        ]);
    }

    public function test_schedule_page_shows_create_button_for_full_access_only(): void
    {
        $this->get('/production/schedule')
            ->assertOk()
            ->assertSee('Create Schedule');

        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $production = Module::query()->where('slug', 'production')->firstOrFail();
        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', $production->id)
            ->update(['access_level' => 'view']);

        $this->actingAs(User::factory()->create(['role_id' => $productionManager->id]));

        $this->get('/production/schedule')
            ->assertOk()
            ->assertDontSee('Create Schedule');
    }
}
