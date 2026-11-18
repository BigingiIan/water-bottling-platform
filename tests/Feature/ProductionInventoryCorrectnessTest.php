<?php

namespace Tests\Feature;

use App\Livewire\CreateScheduleModal;
use App\Livewire\InventoryOverview;
use App\Livewire\LogProductionModal;
use App\Livewire\ProductionRuns;
use App\Models\LeakageReport;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionLine;
use App\Models\ProductionLog;
use App\Models\ProductionSchedule;
use App\Models\Role;
use App\Models\User;
use App\Models\WarehouseStock;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionInventoryCorrectnessTest extends TestCase
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

    public function test_production_cannot_be_logged_against_a_completed_run(): void
    {
        $schedule = $this->createSchedule('complete');

        Livewire::test(LogProductionModal::class)
            ->call('openModal', $schedule->id)
            ->set('units', '180')
            ->call('saveLog')
            ->assertHasErrors(['scheduleId'])
            ->assertSee('already complete');

        $this->assertSame(0, ProductionLog::count());
    }

    public function test_a_second_run_cannot_start_on_a_line_with_an_active_run(): void
    {
        $active = $this->createSchedule('active');
        $queued = $this->createSchedule('queued', '12:00:00', $active->production_line_id);
        $otherLine = $this->createSchedule('queued', '13:00:00');

        Livewire::test(ProductionRuns::class)
            ->call('startRun', $queued->id)
            ->assertHasErrors(['runTransition'])
            ->call('startRun', $otherLine->id);

        $this->assertSame('queued', $queued->fresh()->status);
        $this->assertSame('active', $otherLine->fresh()->status);
    }

    public function test_adjustment_direction_must_match_the_reason(): void
    {
        $stock = $this->createStockLine(100);

        foreach (['damaged' => '500', 'leakage_writeoff' => '500', 'customer_return' => '-50'] as $reason => $change) {
            Livewire::test(InventoryOverview::class)
                ->call('openAdjustmentModal', $stock->id)
                ->set('adjustmentReasonType', $reason)
                ->set('adjustmentChange', $change)
                ->set('adjustmentNote', 'Wrong direction')
                ->call('saveAdjustment')
                ->assertHasErrors(['adjustmentChange']);
        }

        $this->assertSame(100, (int) $stock->fresh()->current_level);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_a_stale_review_modal_cannot_reopen_a_resolved_leakage_report(): void
    {
        $stock = $this->createStockLine(100);
        $report = LeakageReport::create([
            'batch_id' => $stock->batch_id,
            'expected_volume' => 100,
            'actual_volume' => 90,
            'discrepancy' => -10,
            'report_date' => today()->toDateString(),
        ]);

        $component = Livewire::test(InventoryOverview::class, ['activeTab' => 'leakage'])
            ->call('openReviewModal', $report->id, 'acknowledge');

        $report->update(['status' => 'resolved']);

        $component->call('saveReview')->assertHasErrors(['reviewNote']);

        $this->assertSame('resolved', $report->fresh()->status);
    }

    public function test_schedule_modal_renders_the_stored_forecast_without_calling_the_ml_service(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $product = Product::create(['name' => 'Aqua 1L', 'unit_type' => '1L']);
        DB::table('forecast_records')->insert([
            'product_id' => $product->id,
            'forecast_date' => today()->toDateString(),
            'predicted_demand' => 2640,
            'horizon_days' => 1,
            'model_version' => 'v1',
            'rmse_score' => 1,
            'generated_at' => now(),
        ]);

        Livewire::test(CreateScheduleModal::class)
            ->call('openModal')
            ->set('productSku', '1L')
            ->assertSee('2,640')
            ->call('applyForecast')
            ->assertSet('targetUnits', '2640');

        Http::assertNothingSent();
    }

    private function createSchedule(string $status, string $time = '10:30:00', ?int $lineId = null): ProductionSchedule
    {
        $lineId ??= ProductionLine::create(['name' => 'Line '.$time, 'status' => 'active'])->id;

        return ProductionSchedule::create([
            'product_sku' => '1L',
            'production_line_id' => $lineId,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => $time,
            'target_units' => 2500,
            'status' => $status,
        ]);
    }

    private function createStockLine(int $level): WarehouseStock
    {
        $product = Product::create(['name' => 'Adjustment water', 'unit_type' => '1L', 'reorder_threshold' => 100]);
        $batch = ProductionBatch::create([
            'user_id' => auth()->id(),
            'batch_date' => today()->toDateString(),
            'raw_water_volume' => 100,
            'bottled_volume' => 100,
            'status' => 'completed',
        ]);

        return WarehouseStock::create([
            'batch_id' => $batch->id,
            'product_id' => $product->id,
            'current_level' => $level,
            'warehouse_location' => 'Bay A1',
        ]);
    }
}
