<?php

namespace Tests\Feature;

use App\Livewire\LogProductionModal;
use App\Models\Module;
use App\Models\ProductionLine;
use App\Models\ProductionLog;
use App\Models\ProductionSchedule;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class LogProductionModalTest extends TestCase
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

        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $this->actingAs(User::factory()->create(['role_id' => $administrator->id]));
    }

    private function createActiveSchedule(): ProductionSchedule
    {
        $line = ProductionLine::create(['name' => 'Line 02', 'status' => 'active']);

        return ProductionSchedule::create([
            'product_sku' => '1L',
            'production_line_id' => $line->id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => '10:30:00',
            'target_units' => 2500,
            'status' => 'active',
        ]);
    }

    public function test_save_creates_log_tied_to_operator_and_schedule(): void
    {
        $schedule = $this->createActiveSchedule();
        $operator = auth()->user();

        Livewire::test(LogProductionModal::class)
            ->call('openModal', $schedule->id)
            ->set('units', '180')
            ->set('qualityStatus', 'passed')
            ->set('notes', 'minor label misalignment on batch')
            ->call('saveLog')
            ->assertHasNoErrors()
            ->assertDispatched('production-logged', scheduleId: $schedule->id);

        $this->assertDatabaseHas('production_logs', [
            'production_schedule_id' => $schedule->id,
            'operator_id' => $operator->id,
            'units_logged' => 180,
            'quality_status' => 'passed',
            'notes' => 'minor label misalignment on batch',
        ]);
        $this->assertNotNull(ProductionLog::firstOrFail()->logged_at);
    }

    public function test_save_validates_units_quality_and_schedule(): void
    {
        $schedule = $this->createActiveSchedule();

        Livewire::test(LogProductionModal::class)
            ->call('openModal', $schedule->id)
            ->set('units', '0')
            ->set('qualityStatus', 'invalid')
            ->call('saveLog')
            ->assertHasErrors(['units', 'qualityStatus']);

        Livewire::test(LogProductionModal::class)
            ->call('saveLog')
            ->assertHasErrors(['scheduleId']);

        $this->assertSame(0, ProductionLog::count());
    }

    public function test_open_rejects_nonexistent_schedule(): void
    {
        Livewire::test(LogProductionModal::class)
            ->call('openModal', 999)
            ->assertStatus(404);
    }

    public function test_open_without_schedule_offers_today_open_runs(): void
    {
        $this->createActiveSchedule();

        Livewire::test(LogProductionModal::class)
            ->call('openModal')
            ->assertSee('Select run…')
            ->assertSee('1L');
    }

    public function test_view_access_allows_reading_but_not_saving_logs(): void
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $production = Module::query()->where('slug', 'production')->firstOrFail();
        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', $production->id)
            ->update(['access_level' => 'view']);

        $this->actingAs(User::factory()->create(['role_id' => $productionManager->id]));
        $schedule = $this->createActiveSchedule();

        $this->get('/production/schedule')->assertOk();

        Livewire::test(LogProductionModal::class)
            ->call('openModal', $schedule->id)
            ->set('units', '180')
            ->call('saveLog')
            ->assertStatus(403);

        $this->assertSame(0, ProductionLog::count());
    }

    public function test_schedule_table_refreshes_when_log_is_saved(): void
    {
        $schedule = $this->createActiveSchedule();

        Livewire::test(LogProductionModal::class)
            ->call('openModal', $schedule->id)
            ->set('units', '180')
            ->call('saveLog')
            ->assertHasNoErrors();

        $this->get('/production/schedule')
            ->assertOk()
            ->assertSee('180');
    }
}
