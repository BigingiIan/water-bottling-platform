<?php

namespace Tests\Feature;

use App\Livewire\ProductionHistory;
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

class ProductionRunsHistoryTest extends TestCase
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

    private function createSchedule(string $sku, string $time, int $target, string $status, string $lineName = 'Line 01'): ProductionSchedule
    {
        $line = ProductionLine::firstOrCreate(['name' => $lineName], ['status' => 'active']);

        return ProductionSchedule::create([
            'product_sku' => $sku,
            'production_line_id' => $line->id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => $time,
            'target_units' => $target,
            'status' => $status,
        ]);
    }

    public function test_runs_page_shows_open_runs_but_not_completed_ones(): void
    {
        $this->createSchedule('1L', '10:30:00', 2500, 'active', 'Line 02');
        $this->createSchedule('5L', '13:00:00', 1500, 'queued');
        $this->createSchedule('500ml', '08:00:00', 3000, 'complete');

        $this->get('/production/runs')
            ->assertOk()
            ->assertSee('Production Runs')
            ->assertSee('10:30')
            ->assertSee('13:00')
            ->assertSee('Log production for')
            ->assertDontSee('08:00');
    }

    public function test_history_page_shows_final_actuals_from_logs(): void
    {
        $schedule = $this->createSchedule('500ml', '08:00:00', 3000, 'complete');
        ProductionLog::create([
            'production_schedule_id' => $schedule->id,
            'operator_id' => auth()->id(),
            'units_logged' => 3120,
            'logged_at' => now(),
            'quality_status' => 'passed',
        ]);

        $this->get('/production/history')
            ->assertOk()
            ->assertSee('Production History')
            ->assertSee('500ml')
            ->assertSee('3,120');
    }

    public function test_runs_and_history_require_production_access(): void
    {
        $inventoryStaff = Role::query()->where('slug', 'inventory-staff')->firstOrFail();
        $this->actingAs(User::factory()->create(['role_id' => $inventoryStaff->id]));

        $this->get('/production/runs')->assertForbidden();
        $this->get('/production/history')->assertForbidden();
    }

    public function test_view_access_opens_runs_read_only(): void
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $production = Module::query()->where('slug', 'production')->firstOrFail();
        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', $production->id)
            ->update(['access_level' => 'view']);

        $this->actingAs(User::factory()->create(['role_id' => $productionManager->id]));
        $this->createSchedule('1L', '10:30:00', 2500, 'active', 'Line 02');

        $this->get('/production/runs')
            ->assertOk()
            ->assertDontSee('Log production for')
            ->assertSee('Read-only access');

        $this->get('/production/history')->assertOk();
    }

    public function test_sidebar_points_production_items_at_real_routes(): void
    {
        $this->createSchedule('1L', '10:30:00', 2500, 'active', 'Line 02');

        $this->get('/production/schedule')
            ->assertOk()
            ->assertSee('/production/runs')
            ->assertSee('/production/history');
    }

    public function test_history_filters_by_date_range_sku_line_and_quality(): void
    {
        $lineOne = ProductionLine::create(['name' => 'Line 01', 'status' => 'active']);
        $lineTwo = ProductionLine::create(['name' => 'Line 02', 'status' => 'active']);
        $yesterday = today()->subDay()->toDateString();

        $flagged = ProductionSchedule::create([
            'product_sku' => '1L',
            'production_line_id' => $lineTwo->id,
            'scheduled_date' => $yesterday,
            'scheduled_time' => '10:30:00',
            'target_units' => 2500,
            'status' => 'complete',
        ]);
        ProductionLog::create([
            'production_schedule_id' => $flagged->id,
            'operator_id' => auth()->id(),
            'units_logged' => 2300,
            'logged_at' => now()->subDay(),
            'quality_status' => 'flagged',
        ]);
        ProductionSchedule::create([
            'product_sku' => '5L',
            'production_line_id' => $lineOne->id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => '13:00:00',
            'target_units' => 1500,
            'status' => 'complete',
        ]);

        Livewire::test(ProductionHistory::class)
            ->assertSee('10:30')
            ->assertSee('13:00')
            ->set('dateFrom', $yesterday)
            ->set('dateTo', $yesterday)
            ->assertSee('10:30')
            ->assertDontSee('13:00')
            ->set('dateFrom', today()->subDays(6)->toDateString())
            ->set('dateTo', today()->toDateString())
            ->set('skuFilter', '5L')
            ->assertSee('13:00')
            ->assertDontSee('10:30')
            ->set('skuFilter', '')
            ->set('lineFilter', (string) $lineTwo->id)
            ->assertSee('10:30')
            ->assertDontSee('13:00')
            ->set('lineFilter', '')
            ->set('qualityFilter', 'flagged')
            ->assertSee('10:30')
            ->assertDontSee('13:00')
            ->set('qualityFilter', 'passed')
            ->assertDontSee('10:30')
            ->assertDontSee('13:00');
    }

    public function test_history_shows_target_vs_actual_variance(): void
    {
        $schedule = $this->createSchedule('500ml', '08:00:00', 3000, 'complete');
        ProductionLog::create([
            'production_schedule_id' => $schedule->id,
            'operator_id' => auth()->id(),
            'units_logged' => 3120,
            'logged_at' => now(),
            'quality_status' => 'passed',
        ]);

        Livewire::test(ProductionHistory::class)
            ->assertSee('3,000')
            ->assertSee('3,120')
            ->assertSee('+4%');
    }

    public function test_history_csv_export_downloads_filtered_rows(): void
    {
        $this->createSchedule('500ml', '08:00:00', 3000, 'complete');

        Livewire::test(ProductionHistory::class)
            ->call('exportCsv')
            ->assertFileDownloaded('production-history-'.today()->subDays(6)->toDateString().'_'.today()->toDateString().'.csv');
    }

    public function test_schedule_page_has_no_tabs_or_dead_buttons(): void
    {
        $this->get('/production/schedule')
            ->assertOk()
            ->assertSee('Create Schedule')
            ->assertDontSee("Today's Schedule")
            ->assertDontSee('Active Runs')
            ->assertDontSee('Change Date')
            ->assertDontSee('⋯');
    }
}
