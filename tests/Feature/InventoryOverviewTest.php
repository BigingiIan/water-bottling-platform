<?php

namespace Tests\Feature;

use App\Livewire\InventoryOverview;
use App\Livewire\ProductionScheduleTable;
use App\Models\LeakageReport;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseStock;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryOverviewTest extends TestCase
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

    public function test_overview_shows_forecast_tiles_attention_and_recent_movements(): void
    {
        $products = $this->createStockOverviewData();

        $response = $this->get(route('inventory.overview'));

        $response->assertOk()
            ->assertSee('Stock Overview')
            ->assertSee('20 units')
            ->assertSee('1.5 days')
            ->assertSee('No forecast')
            ->assertSee('Needs attention')
            ->assertSee('Suggested run: 20 units')
            ->assertSee(route('production.schedule', ['sku' => $products[0]->unit_type]))
            ->assertSee('Recent movements')
            ->assertSee('Movement event 0')
            ->assertDontSee('Movement event 5');
    }

    public function test_stock_table_filters_by_product_location_and_product_status(): void
    {
        $products = $this->createStockOverviewData();

        $response = $this->get(route('inventory.overview', [
            'productFilter' => $products[0]->id,
            'locationFilter' => 'North',
            'statusFilter' => 'critical',
        ]));

        $response->assertOk()
            ->assertSee('2 stock lines')
            ->assertSee('name="productFilter"', false)
            ->assertSee('name="locationFilter"', false)
            ->assertSee('name="statusFilter"', false);
    }

    public function test_user_with_no_inventory_access_receives_forbidden_response(): void
    {
        $noAccessRole = Role::query()->where('slug', 'production-staff')->firstOrFail();
        $this->actingAs(User::factory()->create(['role_id' => $noAccessRole->id]))
            ->get(route('inventory.overview'))
            ->assertForbidden();
    }

    public function test_batches_default_to_the_last_fourteen_days_and_paginate(): void
    {
        $this->travelTo(now()->setDate(2026, 4, 20)->startOfDay());
        $inRange = $this->createBatch('2026-04-20');
        $outOfRange = $this->createBatch('2026-04-06');
        for ($index = 0; $index < 15; $index++) {
            $this->createBatch('2026-04-08');
        }

        Livewire::withQueryParams(['tab' => 'batches'])
            ->test(InventoryOverview::class)
            ->assertSee('16 batches')
            ->assertSee('B-'.str_pad((string) $inRange->id, 5, '0', STR_PAD_LEFT))
            ->assertDontSee('B-'.str_pad((string) $outOfRange->id, 5, '0', STR_PAD_LEFT));
    }

    public function test_batches_filter_by_date_and_status(): void
    {
        $matching = $this->createBatch('2026-04-10', 'rejected');
        $wrongStatus = $this->createBatch('2026-04-10', 'completed');
        $outOfRange = $this->createBatch('2026-04-08', 'rejected');

        Livewire::withQueryParams([
            'tab' => 'batches',
            'batchDateFrom' => '2026-04-09',
            'batchDateTo' => '2026-04-11',
            'batchStatus' => 'rejected',
        ])
            ->test(InventoryOverview::class)
            ->assertSee('1 batches')
            ->assertSee('B-'.str_pad((string) $matching->id, 5, '0', STR_PAD_LEFT))
            ->assertDontSee('B-'.str_pad((string) $wrongStatus->id, 5, '0', STR_PAD_LEFT))
            ->assertDontSee('B-'.str_pad((string) $outOfRange->id, 5, '0', STR_PAD_LEFT));
    }

    public function test_leakage_reports_default_query_shows_all_flags_and_recent_attention_count(): void
    {
        $this->travelTo(now()->setDate(2026, 4, 20)->startOfDay());
        $batch = $this->createBatch();
        $this->createLeakageReport($batch, 100, 98);
        $this->createLeakageReport($batch, 100, 95);
        $this->createLeakageReport($batch, 100, 94);
        $this->createLeakageReport($batch, 100, 98.1);
        $this->createLeakageReport($batch, 100, 90, '2026-04-01');
        $this->createLeakageReport($batch, 100, 90, '2026-04-21');
        Livewire::withQueryParams(['tab' => 'leakage'])
            ->test(InventoryOverview::class)
            ->assertSee('6 reports')
            ->assertSee('Normal')
            ->assertSee('Review')
            ->assertSee('Investigate')
            ->assertSee('3 leakage reports need attention in the last 14 days');
    }

    public function test_leakage_reports_filter_by_flag(): void
    {
        $batch = $this->createBatch();
        $this->createLeakageReport($batch, 100, 98);
        $this->createLeakageReport($batch, 100, 94);

        Livewire::withQueryParams([
            'tab' => 'leakage',
            'leakageFlagFilter' => 'Review',
        ])
            ->test(InventoryOverview::class)
            ->assertSee('1 reports')
            ->assertSee('Review')
            ->assertDontSee('6.00%');
    }

    public function test_full_access_user_can_create_a_computed_leakage_report(): void
    {
        $batch = $this->createBatch();

        Livewire::test(InventoryOverview::class)
            ->call('openLeakageReportModal')
            ->set('leakageBatchId', (string) $batch->id)
            ->set('leakageExpectedVolume', '1000')
            ->set('leakageActualVolume', '970')
            ->call('saveLeakageReport')
            ->assertHasNoErrors()
            ->assertSet('leakageReportOpen', false)
            ->assertSee('Leakage report recorded.');

        $this->assertDatabaseHas('leakage_reports', [
            'batch_id' => $batch->id,
            'expected_volume' => 1000,
            'actual_volume' => 970,
            'discrepancy' => -30,
        ]);
    }

    public function test_view_only_user_cannot_create_a_leakage_report(): void
    {
        $batch = $this->createBatch();
        $viewRole = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $this->actingAs(User::factory()->create(['role_id' => $viewRole->id]));

        Livewire::test(InventoryOverview::class)
            ->call('openLeakageReportModal')
            ->assertStatus(403);

        Livewire::test(InventoryOverview::class)
            ->set('leakageBatchId', (string) $batch->id)
            ->set('leakageExpectedVolume', '100')
            ->set('leakageActualVolume', '95')
            ->call('saveLeakageReport')
            ->assertStatus(403);

        $this->assertDatabaseCount('leakage_reports', 0);
    }

    public function test_movements_default_query_is_newest_first_with_twenty_five_per_page(): void
    {
        $this->travelTo(now()->setDate(2026, 4, 20)->startOfDay());
        $stock = $this->createStockLine(100);
        for ($index = 0; $index < 26; $index++) {
            $this->createMovement($stock, 'adjustment', 'Movement '.$index);
        }
        $this->createMovement($stock, 'sale', 'Outside date range', now()->subDays(8));

        Livewire::withQueryParams(['tab' => 'movements'])
            ->test(InventoryOverview::class)
            ->assertSet('movementDateFrom', '2026-04-14')
            ->assertSee('26 movements')
            ->assertSee('Movement 25')
            ->assertDontSee('Movement 0')
            ->assertDontSee('Outside date range');
    }

    public function test_movements_filter_by_type_product_and_date(): void
    {
        $matchingStock = $this->createStockLine(100);
        $otherStock = $this->createStockLine(100);
        $this->createMovement($matchingStock, 'return', 'Matching movement', now()->subDay());
        $this->createMovement($matchingStock, 'adjustment', 'Wrong type');
        $this->createMovement($otherStock, 'return', 'Wrong product');
        $this->createMovement($matchingStock, 'return', 'Wrong date', now()->subDays(10));

        Livewire::withQueryParams([
            'tab' => 'movements',
            'movementTypeFilter' => 'return',
            'movementProductFilter' => (string) $matchingStock->product_id,
            'movementDateFrom' => today()->subDays(2)->toDateString(),
            'movementDateTo' => today()->toDateString(),
        ])
            ->test(InventoryOverview::class)
            ->assertSee('1 movements')
            ->assertSee('Matching movement')
            ->assertDontSee('Wrong type')
            ->assertDontSee('Wrong product')
            ->assertDontSee('Wrong date');
    }

    public function test_plan_a_run_sku_query_parameter_preselects_the_schedule_filter(): void
    {
        Livewire::withQueryParams(['sku' => 'SKU-0'])
            ->test(ProductionScheduleTable::class)
            ->assertSet('skuFilter', 'SKU-0');
    }

    public function test_overview_query_count_stays_bounded_for_five_products_and_twenty_stock_lines(): void
    {
        $this->createStockOverviewData();
        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            $queryCount++;
        });

        Livewire::test(InventoryOverview::class)
            ->assertSee('Warehouse stock');

        $this->assertLessThanOrEqual(18, $queryCount);
    }

    public function test_adjustment_records_stock_movement_and_audit_row(): void
    {
        $stock = $this->createStockLine(100);

        Livewire::test(InventoryOverview::class)
            ->call('openAdjustmentModal', $stock->id)
            ->set('adjustmentReasonType', 'damaged')
            ->set('adjustmentChange', '-12')
            ->set('adjustmentNote', 'Damaged during offload')
            ->call('saveAdjustment')
            ->assertHasNoErrors()
            ->assertSet('adjustmentOpen', false)
            ->assertSee('Stock adjustment recorded.');

        $this->assertSame(88, (int) $stock->fresh()->current_level);
        $this->assertDatabaseHas('stock_movements', [
            'warehouse_stock_id' => $stock->id,
            'type' => 'adjustment',
            'quantity_change' => -12,
            'level_after' => 88,
            'reason' => 'Damaged during offload',
            'user_id' => auth()->id(),
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'inventory.stock_movement',
            'table_name' => 'warehouse_stock',
            'user_id' => auth()->id(),
        ]);
    }

    public function test_adjustment_displays_validation_and_below_zero_errors_inline(): void
    {
        $stock = $this->createStockLine(5);

        Livewire::test(InventoryOverview::class)
            ->call('openAdjustmentModal', $stock->id)
            ->set('adjustmentChange', '-6')
            ->set('adjustmentNote', 'Too much')
            ->call('saveAdjustment')
            ->assertHasErrors(['adjustmentChange'])
            ->assertSee('The adjustment cannot reduce this stock line below zero.');

        $this->assertSame(5, (int) $stock->fresh()->current_level);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('audit_log', 0);
    }

    public function test_adjustment_requires_nonzero_change_and_a_note_of_at_least_five_characters(): void
    {
        $stock = $this->createStockLine(10);

        Livewire::test(InventoryOverview::class)
            ->call('openAdjustmentModal', $stock->id)
            ->set('adjustmentChange', '0')
            ->set('adjustmentNote', 'bad')
            ->call('saveAdjustment')
            ->assertHasErrors([
                'adjustmentChange' => 'not_in',
                'adjustmentNote' => 'min',
            ]);

        $this->assertSame(10, (int) $stock->fresh()->current_level);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_adjustment_requires_a_note(): void
    {
        $stock = $this->createStockLine(10);

        Livewire::test(InventoryOverview::class)
            ->call('openAdjustmentModal', $stock->id)
            ->set('adjustmentChange', '1')
            ->set('adjustmentNote', '')
            ->call('saveAdjustment')
            ->assertHasErrors(['adjustmentNote' => 'required']);
    }

    public function test_adjustment_reason_type_selects_the_stock_movement_type(): void
    {
        $stock = $this->createStockLine(100);
        $reasonTypes = [
            'count_correction' => 'adjustment',
            'damaged' => 'adjustment',
            'leakage_writeoff' => 'leakage_writeoff',
            'customer_return' => 'return',
        ];

        foreach ($reasonTypes as $reasonType => $movementType) {
            Livewire::test(InventoryOverview::class)
                ->call('openAdjustmentModal', $stock->id)
                ->set('adjustmentReasonType', $reasonType)
                ->set('adjustmentChange', '1')
                ->set('adjustmentNote', 'Inventory reason')
                ->call('saveAdjustment')
                ->assertHasNoErrors();
        }

        foreach (array_count_values($reasonTypes) as $movementType => $count) {
            $this->assertSame($count, DB::table('stock_movements')->where('type', $movementType)->count());
        }
    }

    public function test_view_only_inventory_user_cannot_open_or_save_write_actions(): void
    {
        $stock = $this->createStockLine(100);
        $batch = ProductionBatch::query()->findOrFail($stock->batch_id);
        $viewRole = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $this->actingAs(User::factory()->create(['role_id' => $viewRole->id]));

        $this->get(route('inventory.overview'))
            ->assertOk()
            ->assertDontSee('Adjust Stock')
            ->assertDontSee('Record Stock-In')
            ->assertDontSee('New Report');

        Livewire::test(InventoryOverview::class)
            ->call('openAdjustmentModal', $stock->id)
            ->assertStatus(403);

        Livewire::test(InventoryOverview::class)
            ->set('adjustmentStockId', $stock->id)
            ->set('adjustmentChange', '-1')
            ->set('adjustmentNote', 'Unauthorized')
            ->call('saveAdjustment')
            ->assertStatus(403);

        Livewire::test(InventoryOverview::class)
            ->call('openStockInModal')
            ->assertStatus(403);

        Livewire::test(InventoryOverview::class)
            ->set('stockInProductId', (string) $stock->product_id)
            ->set('stockInBatchId', (string) $stock->batch_id)
            ->set('stockInLocation', 'Bay A1')
            ->set('stockInQuantity', '1')
            ->call('saveStockIn')
            ->assertStatus(403);

        Livewire::test(InventoryOverview::class)
            ->call('openLeakageReportModal')
            ->assertStatus(403);

        Livewire::test(InventoryOverview::class)
            ->set('leakageBatchId', (string) $batch->id)
            ->set('leakageExpectedVolume', '100')
            ->set('leakageActualVolume', '95')
            ->call('saveLeakageReport')
            ->assertStatus(403);

        $this->assertSame(100, (int) $stock->fresh()->current_level);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('audit_log', 0);
    }

    public function test_sequential_adjustments_cannot_reduce_stock_below_zero(): void
    {
        $stock = $this->createStockLine(5);

        Livewire::test(InventoryOverview::class)
            ->call('openAdjustmentModal', $stock->id)
            ->set('adjustmentChange', '-3')
            ->set('adjustmentNote', 'First adjustment')
            ->call('saveAdjustment')
            ->assertHasNoErrors();

        Livewire::test(InventoryOverview::class)
            ->call('openAdjustmentModal', $stock->id)
            ->set('adjustmentChange', '-3')
            ->set('adjustmentNote', 'Second adjustment')
            ->call('saveAdjustment')
            ->assertHasErrors(['adjustmentChange'])
            ->assertSee('The adjustment cannot reduce this stock line below zero.');

        $this->assertSame(2, (int) $stock->fresh()->current_level);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('audit_log', 1);
    }

    public function test_stock_in_creates_a_line_and_records_a_movement(): void
    {
        $product = Product::create([
            'name' => 'Opening water',
            'unit_type' => '750ml',
            'reorder_threshold' => 50,
        ]);
        $batch = $this->createBatch();

        Livewire::test(InventoryOverview::class)
            ->call('openStockInModal')
            ->set('stockInProductId', (string) $product->id)
            ->set('stockInBatchId', (string) $batch->id)
            ->set('stockInLocation', 'Bay C2')
            ->set('stockInQuantity', '37')
            ->call('saveStockIn')
            ->assertHasNoErrors()
            ->assertSet('stockInOpen', false);

        $stock = WarehouseStock::query()
            ->where('product_id', $product->id)
            ->where('batch_id', $batch->id)
            ->where('warehouse_location', 'Bay C2')
            ->firstOrFail();

        $this->assertSame(37, (int) $stock->current_level);
        $this->assertDatabaseHas('stock_movements', [
            'warehouse_stock_id' => $stock->id,
            'type' => 'stock_in',
            'quantity_change' => 37,
            'level_after' => 37,
            'user_id' => auth()->id(),
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'inventory.stock_movement',
            'table_name' => 'warehouse_stock',
        ]);
    }

    public function test_stock_in_reuses_a_matching_line_and_adds_a_movement(): void
    {
        $stock = $this->createStockLine(25);

        Livewire::test(InventoryOverview::class)
            ->call('openStockInModal')
            ->set('stockInProductId', (string) $stock->product_id)
            ->set('stockInBatchId', (string) $stock->batch_id)
            ->set('stockInLocation', $stock->warehouse_location)
            ->set('stockInQuantity', '15')
            ->call('saveStockIn')
            ->assertHasNoErrors();

        $this->assertSame(40, (int) $stock->fresh()->current_level);
        $this->assertSame(1, WarehouseStock::query()
            ->where('product_id', $stock->product_id)
            ->where('batch_id', $stock->batch_id)
            ->where('warehouse_location', $stock->warehouse_location)
            ->count());
        $this->assertDatabaseHas('stock_movements', [
            'warehouse_stock_id' => $stock->id,
            'type' => 'stock_in',
            'quantity_change' => 15,
            'level_after' => 40,
        ]);
    }

    private function createStockLine(int $level): WarehouseStock
    {
        $product = Product::create([
            'name' => 'Adjustment water',
            'unit_type' => '1L',
            'reorder_threshold' => 100,
        ]);

        return WarehouseStock::create([
            'batch_id' => $this->createBatch()->id,
            'product_id' => $product->id,
            'current_level' => $level,
            'warehouse_location' => 'Bay A1',
        ]);
    }

    private function createBatch(?string $date = null, string $status = 'completed'): ProductionBatch
    {
        return ProductionBatch::create([
            'user_id' => auth()->id(),
            'batch_date' => $date ?? today()->toDateString(),
            'raw_water_volume' => 100,
            'bottled_volume' => 100,
            'status' => $status,
        ]);
    }

    private function createLeakageReport(
        ProductionBatch $batch,
        float $expected,
        float $actual,
        ?string $date = null,
    ): LeakageReport {
        return LeakageReport::create([
            'batch_id' => $batch->id,
            'expected_volume' => $expected,
            'actual_volume' => $actual,
            'discrepancy' => $actual - $expected,
            'report_date' => $date ?? today()->toDateString(),
        ]);
    }

    private function createMovement(
        WarehouseStock $stock,
        string $type,
        string $reference,
        ?Carbon $createdAt = null,
    ): StockMovement {
        $createdAt ??= now();
        $movement = StockMovement::create([
            'warehouse_stock_id' => $stock->id,
            'product_id' => $stock->product_id,
            'type' => $type,
            'quantity_change' => 1,
            'level_after' => 101,
            'reference' => $reference,
            'user_id' => auth()->id(),
        ]);

        DB::table('stock_movements')
            ->where('id', $movement->id)
            ->update(['created_at' => $createdAt->toDateTimeString()]);

        return $movement;
    }

    /**
     * @return list<Product>
     */
    private function createStockOverviewData(): array
    {
        $products = [];
        for ($index = 0; $index < 5; $index++) {
            $products[] = Product::create([
                'name' => 'Test product '.$index,
                'unit_type' => 'SKU-'.$index,
                'reorder_threshold' => 100,
            ]);
        }

        $batch = ProductionBatch::create([
            'user_id' => auth()->id(),
            'batch_date' => today()->toDateString(),
            'raw_water_volume' => 100,
            'bottled_volume' => 100,
            'status' => 'completed',
        ]);

        foreach ($products as $index => $product) {
            $lineLevel = [5, 15, 25, 30, 40][$index];
            for ($line = 0; $line < 4; $line++) {
                WarehouseStock::create([
                    'batch_id' => $batch->id,
                    'product_id' => $product->id,
                    'current_level' => $lineLevel,
                    'minimum_threshold' => 20,
                    'warehouse_location' => $line % 2 === 0 ? 'North' : 'South',
                ]);
            }
        }

        $stockLine = WarehouseStock::query()->where('product_id', $products[0]->id)->firstOrFail();
        for ($index = 0; $index < 6; $index++) {
            DB::table('stock_movements')->insert([
                'warehouse_stock_id' => $stockLine->id,
                'product_id' => $products[0]->id,
                'type' => 'adjustment',
                'quantity_change' => -1,
                'level_after' => 19,
                'reason' => 'Movement event '.$index,
                'user_id' => auth()->id(),
                'created_at' => now()->subMinutes($index),
            ]);
        }

        DB::table('forecast_records')->insert([
            'product_id' => $products[0]->id,
            'forecast_date' => today()->addDay()->toDateString(),
            'predicted_demand' => 13,
            'horizon_days' => 1,
            'model_version' => 'v2',
            'rmse_score' => 1,
        ]);

        return $products;
    }
}
