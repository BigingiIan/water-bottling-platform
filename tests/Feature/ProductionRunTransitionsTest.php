<?php

namespace Tests\Feature;

use App\Livewire\ProductionRuns;
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

class ProductionRunTransitionsTest extends TestCase
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

    private function createLog(ProductionSchedule $schedule, int $units = 180, string $quality = 'passed', ?string $notes = null): ProductionLog
    {
        return ProductionLog::create([
            'production_schedule_id' => $schedule->id,
            'operator_id' => auth()->id(),
            'units_logged' => $units,
            'logged_at' => now(),
            'quality_status' => $quality,
            'notes' => $notes,
        ]);
    }

    public function test_start_moves_queued_run_to_active(): void
    {
        $schedule = $this->createSchedule('5L', '13:00:00', 1500, 'queued');

        Livewire::test(ProductionRuns::class)
            ->call('startRun', $schedule->id)
            ->assertHasNoErrors();

        $this->assertSame('active', $schedule->fresh()->status);
    }

    public function test_start_is_blocked_when_line_already_has_active_run(): void
    {
        $this->createSchedule('1L', '10:30:00', 2500, 'active');
        $queued = $this->createSchedule('5L', '13:00:00', 1500, 'queued');

        Livewire::test(ProductionRuns::class)
            ->call('startRun', $queued->id)
            ->assertHasErrors(['runTransition']);

        $this->assertSame('queued', $queued->fresh()->status);
    }

    public function test_start_is_rejected_for_non_queued_runs(): void
    {
        $active = $this->createSchedule('1L', '10:30:00', 2500, 'active');

        Livewire::test(ProductionRuns::class)
            ->call('startRun', $active->id)
            ->assertHasErrors(['runTransition']);

        $this->assertSame('active', $active->fresh()->status);
    }

    public function test_complete_moves_active_run_to_complete_when_logs_exist(): void
    {
        $schedule = $this->createSchedule('1L', '10:30:00', 2500, 'active');
        $this->createLog($schedule);

        Livewire::test(ProductionRuns::class)
            ->call('completeRun', $schedule->id)
            ->assertHasNoErrors();

        $this->assertSame('complete', $schedule->fresh()->status);
    }

    public function test_complete_without_logs_requires_confirmation(): void
    {
        $schedule = $this->createSchedule('1L', '10:30:00', 2500, 'active');

        $component = Livewire::test(ProductionRuns::class)
            ->call('completeRun', $schedule->id)
            ->assertHasNoErrors()
            ->assertSet('confirmingCompletion', $schedule->id);

        $this->assertSame('active', $schedule->fresh()->status);

        $component
            ->call('completeRun', $schedule->id)
            ->assertHasNoErrors()
            ->assertSet('confirmingCompletion', null);

        $this->assertSame('complete', $schedule->fresh()->status);
    }

    public function test_complete_is_rejected_for_non_active_runs(): void
    {
        $queued = $this->createSchedule('5L', '13:00:00', 1500, 'queued');

        Livewire::test(ProductionRuns::class)
            ->call('completeRun', $queued->id)
            ->assertHasErrors(['runTransition']);

        $this->assertSame('queued', $queued->fresh()->status);
    }

    public function test_view_access_cannot_start_or_complete_runs(): void
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $production = Module::query()->where('slug', 'production')->firstOrFail();
        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', $production->id)
            ->update(['access_level' => 'view']);

        $this->actingAs(User::factory()->create(['role_id' => $productionManager->id]));
        $queued = $this->createSchedule('5L', '13:00:00', 1500, 'queued');
        $active = $this->createSchedule('1L', '10:30:00', 2500, 'active', 'Line 02');

        Livewire::test(ProductionRuns::class)
            ->call('startRun', $queued->id)
            ->assertStatus(403);

        Livewire::test(ProductionRuns::class)
            ->call('completeRun', $active->id)
            ->assertStatus(403);

        $this->assertSame('queued', $queued->fresh()->status);
        $this->assertSame('active', $active->fresh()->status);
    }

    public function test_detail_view_shows_log_entries_with_operator_and_quality(): void
    {
        $operator = auth()->user();
        $schedule = $this->createSchedule('1L', '10:30:00', 2500, 'active', 'Line 02');
        $this->createLog($schedule, 180, 'passed', 'minor label misalignment on batch');
        $this->createLog($schedule, 200, 'flagged');

        Livewire::test(ProductionRuns::class)
            ->call('selectRun', $schedule->id)
            ->assertSee($operator->name)
            ->assertSee('180')
            ->assertSee('minor label misalignment on batch')
            ->assertSee('Flagged for review')
            ->assertSee('Log production on this run');
    }

    public function test_detail_view_hides_log_action_once_run_is_completed(): void
    {
        $schedule = $this->createSchedule('1L', '10:30:00', 2500, 'active', 'Line 02');
        $this->createLog($schedule);

        Livewire::test(ProductionRuns::class)
            ->call('selectRun', $schedule->id)
            ->assertSee('Log production on this run')
            ->call('completeRun', $schedule->id)
            ->assertDontSee('Log production on this run');
    }
}
