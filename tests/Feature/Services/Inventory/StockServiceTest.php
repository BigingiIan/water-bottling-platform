<?php

namespace Tests\Feature\Services\Inventory;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Inventory\StockService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_sums_stock_across_warehouses_for_the_product(): void
    {
        $product = $this->createProduct(threshold: 100);
        $otherProduct = $this->createProduct(threshold: 100);
        $firstBatch = $this->createBatch();
        $secondBatch = $this->createBatch();

        WarehouseStock::create([
            'batch_id' => $firstBatch->id,
            'product_id' => $product->id,
            'current_level' => 40,
        ]);
        WarehouseStock::create([
            'batch_id' => $secondBatch->id,
            'product_id' => $product->id,
            'current_level' => 60,
        ]);
        WarehouseStock::create([
            'batch_id' => $firstBatch->id,
            'product_id' => $otherProduct->id,
            'current_level' => 500,
        ]);

        $total = app(StockService::class)->totalForProduct($product);

        $this->assertSame(100.0, $total);
    }

    public function test_status_reflects_reorder_threshold_and_critical_half_threshold(): void
    {
        $product = $this->createProduct(threshold: 100);
        $batch = $this->createBatch();
        WarehouseStock::create([
            'batch_id' => $batch->id,
            'product_id' => $product->id,
            'current_level' => 49,
        ]);
        $service = app(StockService::class);

        $this->assertSame('critical', $service->statusForProduct($product));

        WarehouseStock::query()->update(['current_level' => 50]);
        $this->assertSame('low', $service->statusForProduct($product));

        WarehouseStock::query()->update(['current_level' => 100]);
        $this->assertSame('ok', $service->statusForProduct($product));
    }

    public function test_cover_days_uses_the_latest_tomorrow_forecast(): void
    {
        $this->travelTo('2026-10-22 09:00:00');
        $product = $this->createProduct(threshold: 100);
        $batch = $this->createBatch();
        WarehouseStock::create([
            'batch_id' => $batch->id,
            'product_id' => $product->id,
            'current_level' => 200,
        ]);
        $this->createForecast($product, '2026-10-23', 20, 'v1');
        $this->createForecast($product, '2026-10-23', 50, 'v2');
        $this->createForecast($product, '2026-10-23', 1, 'v3', horizonDays: 2);

        $coverDays = app(StockService::class)->coverDays($product);

        $this->assertSame(4.0, $coverDays);
    }

    public function test_cover_days_returns_null_without_a_tomorrow_forecast_or_when_demand_is_zero(): void
    {
        $this->travelTo('2026-10-22 09:00:00');
        $product = $this->createProduct(threshold: 100);

        $this->assertNull(app(StockService::class)->coverDays($product));

        $this->createForecast($product, '2026-10-23', 0, 'v1');

        $this->assertNull(app(StockService::class)->coverDays($product));
    }

    public function test_applying_movement_updates_stock_and_writes_movement_and_audit_rows(): void
    {
        $this->travelTo('2026-10-22 09:00:00');
        $this->seed(RoleSeeder::class);
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'administrator')->valueOrFail('id'),
        ]);
        $product = $this->createProduct(threshold: 100);
        $batch = $this->createBatch();
        $stock = WarehouseStock::create([
            'batch_id' => $batch->id,
            'product_id' => $product->id,
            'current_level' => 100,
        ]);

        $movement = app(StockService::class)->applyMovement(
            $stock,
            'sale',
            -12,
            'sale-123',
            'Customer order',
            $actor,
        );

        $this->assertSame(88, (int) $stock->fresh()->current_level);
        $this->assertSame('2026-10-22 09:00:00', $stock->fresh()->last_updated->toDateTimeString());
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'warehouse_stock_id' => $stock->id,
            'product_id' => $product->id,
            'type' => 'sale',
            'quantity_change' => -12,
            'level_after' => 88,
            'reference' => 'sale-123',
            'reason' => 'Customer order',
            'user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'user_id' => $actor->id,
            'action' => 'inventory.stock_movement',
            'table_name' => 'warehouse_stock',
        ]);
    }

    public function test_applying_movement_without_an_actor_writes_nullable_audit_actor(): void
    {
        $product = $this->createProduct(threshold: 100);
        $batch = $this->createBatch();
        $stock = WarehouseStock::create([
            'batch_id' => $batch->id,
            'product_id' => $product->id,
            'current_level' => 100,
        ]);

        app(StockService::class)->applyMovement($stock, 'adjustment', 5, null, 'Cycle count', null);

        $this->assertDatabaseHas('stock_movements', [
            'warehouse_stock_id' => $stock->id,
            'user_id' => null,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'user_id' => null,
            'action' => 'inventory.stock_movement',
        ]);
    }

    public function test_applying_movement_below_zero_leaves_stock_movement_and_audit_unchanged(): void
    {
        $product = $this->createProduct(threshold: 100);
        $batch = $this->createBatch();
        $stock = WarehouseStock::create([
            'batch_id' => $batch->id,
            'product_id' => $product->id,
            'current_level' => 10,
        ]);

        try {
            app(StockService::class)->applyMovement($stock, 'sale', -11, null, null, null);
            $this->fail('An insufficient stock movement should throw.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(10, (int) $stock->fresh()->current_level);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('audit_log', 0);
    }

    private function createProduct(float $threshold): Product
    {
        return Product::create([
            'name' => 'Test water',
            'unit_type' => '500ml',
            'reorder_threshold' => $threshold,
        ]);
    }

    private function createBatch(): ProductionBatch
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'administrator')->valueOrFail('id'),
        ]);

        return ProductionBatch::create([
            'user_id' => $user->id,
            'batch_date' => today()->toDateString(),
            'raw_water_volume' => 100,
            'bottled_volume' => 100,
            'status' => 'completed',
        ]);
    }

    private function createForecast(
        Product $product,
        string $date,
        float $demand,
        string $modelVersion,
        int $horizonDays = 1,
    ): void {
        DB::table('forecast_records')->insert([
            'product_id' => $product->id,
            'forecast_date' => $date,
            'predicted_demand' => $demand,
            'horizon_days' => $horizonDays,
            'model_version' => $modelVersion,
            'rmse_score' => 1,
        ]);
    }
}
