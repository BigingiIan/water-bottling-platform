<?php

namespace Tests\Feature;

use App\Models\LeakageReport;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Inventory\StockService;
use App\Services\Leakage\StockCountService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockCountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        $this->user = User::factory()->create(['role_id' => Role::query()->where('slug', 'inventory-staff')->valueOrFail('id')]);
        $this->product = Product::factory()->create(['unit_type' => '1L', 'name' => 'Bottled Water 1L', 'volume_litres' => '1.000', 'reorder_threshold' => 0]);
    }

    public function test_shrinkage_arithmetic_from_the_opening_level_with_mixed_movements(): void
    {
        $older = $this->line('2026-10-01', 100);
        $newer = $this->line('2026-10-10', 0);
        $stock = app(StockService::class);
        $stock->applyMovement($newer, 'production', 200, 'PR-1', null, $this->user);
        $stock->applyMovement($older, 'sale', -50, 'TXN-1', null, $this->user);
        $stock->applyMovement($older, 'adjustment', -10, null, 'Damaged', $this->user);
        $stock->applyMovement($newer, 'leakage_writeoff', -5, null, 'Leak', $this->user);
        $stock->applyMovement($newer, 'return', 3, 'RET-1', null, $this->user);
        $this->line('2026-10-05', 999, 'Bay Z9');

        $result = app(StockCountService::class)->record($this->product, 'Bay A1', 230, 'Monthly count', $this->user);
        $assessment = $result['assessment'];

        $this->assertSame(100, $assessment->openingUnits);
        $this->assertFalse($assessment->openingFromCount);
        $this->assertSame(203, $assessment->productionIn);
        $this->assertSame(50, $assessment->salesOut);
        $this->assertSame(-15, $assessment->adjustmentsNet);
        $this->assertSame(238, $assessment->expectedUnits);
        $this->assertSame(238, $assessment->systemUnits);
        $this->assertSame(8, $assessment->shrinkageUnits);
        $this->assertSame('3.361', $assessment->shrinkagePercent);

        $report = LeakageReport::query()->sole();
        $this->assertSame('stock', $report->source);
        $this->assertSame('stock_shrinkage', $report->finding);
        $this->assertSame('review', $report->severity);
        $this->assertSame('Bay A1', $report->warehouse_location);
        $this->assertSame('1L at Bay A1: counted 230 but expected 238 (opening level 100 + 203 produced − 50 sold − 15 adjusted), so 8 units (3.4%) are missing.', $report->explanation);

        $this->assertSame(32.0, (float) $older->fresh()->current_level, 'The 8-unit loss comes out of the oldest batch first (40 → 32).');
        $this->assertSame(198.0, (float) $newer->fresh()->current_level);
        $this->assertSame(230.0, (float) WarehouseStock::query()->where('warehouse_location', 'Bay A1')->sum('current_level'));
        $this->assertSame(999.0, (float) WarehouseStock::query()->where('warehouse_location', 'Bay Z9')->value('current_level'));
        $this->assertSame(['COUNT-'.$result['count']->id], DB::table('stock_movements')->where('reference', 'like', 'COUNT-%')->distinct()->pluck('reference')->all());
    }

    public function test_next_count_starts_from_the_last_count_and_ignores_its_correction(): void
    {
        $line = $this->line('2026-10-01', 100);
        $counts = app(StockCountService::class);
        $counts->record($this->product, 'Bay A1', 90, null, $this->user);
        app(StockService::class)->applyMovement($line, 'sale', -30, 'TXN-2', null, $this->user);

        $assessment = $counts->record($this->product, 'Bay A1', 59, null, $this->user)['assessment'];

        $this->assertTrue($assessment->openingFromCount);
        $this->assertSame(90, $assessment->openingUnits);
        $this->assertSame(0, $assessment->adjustmentsNet, 'The first count\'s own correction is not counted again.');
        $this->assertSame(60, $assessment->expectedUnits);
        $this->assertSame(1, $assessment->shrinkageUnits);
        $this->assertSame('1.666', $assessment->shrinkagePercent);
        $this->assertSame(1, LeakageReport::query()->count(), 'Only the first count (10% short) crossed the review threshold.');
        $this->assertSame(59.0, (float) $line->fresh()->current_level);
    }

    public function test_counts_at_or_above_expected_raise_nothing_and_gains_go_to_the_newest_line(): void
    {
        $older = $this->line('2026-10-01', 40);
        $newer = $this->line('2026-10-10', 60);

        $assessment = app(StockCountService::class)->record($this->product, 'Bay A1', 104, null, $this->user)['assessment'];

        $this->assertSame(-4, $assessment->shrinkageUnits);
        $this->assertNull($assessment->severity);
        $this->assertDatabaseCount('leakage_reports', 0);
        $this->assertSame(40.0, (float) $older->fresh()->current_level);
        $this->assertSame(64.0, (float) $newer->fresh()->current_level);
    }

    public function test_levels_changed_outside_movements_are_called_out(): void
    {
        $line = $this->line('2026-10-01', 100);
        app(StockCountService::class)->record($this->product, 'Bay A1', 100, null, $this->user);
        $line->update(['current_level' => 120]);

        $assessment = app(StockCountService::class)->record($this->product, 'Bay A1', 100, null, $this->user)['assessment'];

        $this->assertSame(100, $assessment->expectedUnits);
        $this->assertSame(120, $assessment->systemUnits);
        $this->assertStringContainsString('The system level was 120, so 20 units changed outside recorded movements.', $assessment->explanation());
    }

    private function line(string $batchDate, int $level, string $location = 'Bay A1'): WarehouseStock
    {
        $batch = ProductionBatch::query()->create([
            'user_id' => $this->user->id,
            'batch_date' => $batchDate,
            'raw_water_volume' => 100,
            'bottled_volume' => 100,
            'status' => 'completed',
        ]);

        return WarehouseStock::query()->create([
            'batch_id' => $batch->id,
            'product_id' => $this->product->id,
            'current_level' => $level,
            'warehouse_location' => $location,
        ]);
    }
}
