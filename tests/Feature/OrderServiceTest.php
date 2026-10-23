<?php

namespace Tests\Feature;

use App\Exceptions\Orders\InvalidOrderTransitionException;
use App\Exceptions\Orders\OrderStockShortageException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStatus;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        $this->administrator = $this->userWithRole('administrator');
        $this->customer = Customer::factory()->create(['name' => 'Naivas Kilimani']);
    }

    public function test_confirm_is_blocked_with_a_message_per_short_line_counting_other_reservations(): void
    {
        $oneLitre = $this->product('1L', '45.00');
        $fiveLitre = $this->product('5L', '120.00');
        $this->stockLine($oneLitre, 300, '2026-10-01');
        $this->stockLine($fiveLitre, 100, '2026-10-01');
        $this->confirmedOrder([$oneLitre->id => 120]);
        $draft = $this->draft([$oneLitre->id => 200, $fiveLitre->id => 50]);

        try {
            $this->orders()->confirm($draft, $this->administrator);
            $this->fail('Confirming should have been blocked.');
        } catch (OrderStockShortageException $exception) {
            $this->assertSame(['1L: need 200, only 180 available (120 reserved by other orders).'], array_values($exception->shortages));
        }

        $draft->refresh();
        $this->assertSame(OrderStatus::Draft, $draft->status);
        $this->assertNull($draft->items()->first()->unit_price);
    }

    public function test_confirm_copies_prices_and_computes_exact_totals(): void
    {
        $product = $this->product('1L', '45.10');
        $this->stockLine($product, 100, '2026-10-01');

        $order = $this->orders()->confirm($this->draft([$product->id => 3]), $this->administrator);
        $product->update(['unit_price_kes' => '99.99']);

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame('45.10', $order->items->first()->unit_price);
        $this->assertSame('135.30', $order->items->first()->line_total);
        $this->assertSame('135.30', $order->refresh()->load('items')->total());
        $this->assertDatabaseHas('audit_log', ['action' => 'orders.status_changed', 'user_id' => $this->administrator->id]);
    }

    public function test_confirm_requires_a_price_on_every_product(): void
    {
        $product = $this->product('1L', null);
        $this->stockLine($product, 100, '2026-10-01');

        $this->expectException(OrderStockShortageException::class);
        $this->expectExceptionMessage('1L: no price is set for this product.');

        $this->orders()->confirm($this->draft([$product->id => 1]), $this->administrator);
    }

    public function test_dispatch_allocates_oldest_batch_first_and_records_movements_sales_and_audit(): void
    {
        $product = $this->product('1L', '45.00');
        $middle = $this->stockLine($product, 50, '2026-10-01', 'Bay B2');
        $oldest = $this->stockLine($product, 30, '2026-09-20', 'Bay B1');
        $newest = $this->stockLine($product, 100, '2026-10-10', 'Bay B3');
        $order = $this->confirmedOrder([$product->id => 70]);

        $dispatched = $this->orders()->dispatch($order, $this->administrator);

        $this->assertSame(OrderStatus::Dispatched, $dispatched->status);
        $this->assertNotNull($dispatched->dispatched_at);
        $this->assertSame([0.0, 10.0, 100.0], [(float) $oldest->fresh()->current_level, (float) $middle->fresh()->current_level, (float) $newest->fresh()->current_level]);
        $this->assertSame(
            [[$oldest->id, -30], [$middle->id, -40]],
            DB::table('stock_movements')->where('reference', $order->order_number)->where('type', 'sale')->orderBy('id')
                ->get(['warehouse_stock_id', 'quantity_change'])->map(fn (object $row): array => [(int) $row->warehouse_stock_id, (int) $row->quantity_change])->all(),
        );
        $sales = DB::table('sales_transactions')->orderBy('id')->get();
        $this->assertSame([$oldest->id, $middle->id], $sales->pluck('stock_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([30.0, 40.0], $sales->pluck('quantity')->map(fn ($quantity): float => (float) $quantity)->all());
        $this->assertSame([1350.0, 1800.0], $sales->pluck('total_value')->map(fn ($total): float => (float) $total)->all());
        $this->assertSame([$this->customer->id], $sales->pluck('customer_id')->unique()->map(fn ($id): int => (int) $id)->values()->all());
        $this->assertTrue($sales->every(fn (object $sale): bool => str_starts_with((string) $sale->transaction_date, '2026-10-22')));
        $this->assertSame(2, DB::table('audit_log')->where('action', 'inventory.stock_movement')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'orders.status_changed')->where('new_values->status', 'dispatched')->count());
    }

    public function test_failed_dispatch_changes_nothing_and_notifies_inventory_roles(): void
    {
        $inventoryStaff = $this->userWithRole('inventory-staff');
        $oneLitre = $this->product('1L', '45.00');
        $fiveLitre = $this->product('5L', '120.00');
        $oneLitreLine = $this->stockLine($oneLitre, 100, '2026-10-01');
        $fiveLitreLine = $this->stockLine($fiveLitre, 40, '2026-10-01');
        $order = $this->confirmedOrder([$oneLitre->id => 60, $fiveLitre->id => 40]);
        $fiveLitreLine->update(['current_level' => 25]);
        $auditRows = DB::table('audit_log')->count();

        try {
            $this->orders()->dispatch($order, $this->administrator);
            $this->fail('Dispatch should have failed.');
        } catch (OrderStockShortageException $exception) {
            $this->assertSame(['5L: need 40, only 25 in stock.'], array_values($exception->shortages));
        }

        $this->assertSame(100.0, (float) $oneLitreLine->fresh()->current_level);
        $this->assertSame(25.0, (float) $fiveLitreLine->fresh()->current_level);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('sales_transactions', 0);
        $this->assertSame($auditRows, DB::table('audit_log')->count());
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $notification = $inventoryStaff->notifications()->sole();
        $this->assertSame('order.dispatch_blocked', $notification->data['type']);
        $this->assertStringContainsString('5L: need 40, only 25 in stock.', $notification->data['body']);
    }

    public function test_cancel_is_allowed_for_draft_and_confirmed_orders_only(): void
    {
        $product = $this->product('1L', '45.00');
        $this->stockLine($product, 100, '2026-10-01');
        $draft = $this->draft([$product->id => 5]);
        $confirmed = $this->confirmedOrder([$product->id => 80]);

        $this->assertSame(OrderStatus::Cancelled, $this->orders()->cancel($draft, $this->administrator)->status);
        $this->assertSame(OrderStatus::Cancelled, $this->orders()->cancel($confirmed, $this->administrator)->status);
        $this->assertNotNull($confirmed->fresh()->cancelled_at);

        $reusable = $this->orders()->confirm($this->draft([$product->id => 100]), $this->administrator);
        $this->assertSame(OrderStatus::Confirmed, $reusable->status, 'Cancelling a confirmed order releases its reservation.');

        $dispatched = $this->orders()->dispatch($reusable, $this->administrator);

        try {
            $this->orders()->cancel($dispatched, $this->administrator);
            $this->fail('A dispatched order must not be cancellable.');
        } catch (InvalidOrderTransitionException $exception) {
            $this->assertStringContainsString('Dispatched orders cannot be cancelled', $exception->getMessage());
        }

        $delivered = $this->orders()->deliver($dispatched, $this->administrator);
        $this->expectException(InvalidOrderTransitionException::class);
        $this->orders()->cancel($delivered, $this->administrator);
    }

    public function test_deliver_only_records_the_delivery_time(): void
    {
        $product = $this->product('1L', '45.00');
        $line = $this->stockLine($product, 100, '2026-10-01');
        $dispatched = $this->orders()->dispatch($this->confirmedOrder([$product->id => 10]), $this->administrator);
        $salesBefore = DB::table('sales_transactions')->count();

        $this->travel(2)->hours();
        $delivered = $this->orders()->deliver($dispatched, $this->administrator);

        $this->assertSame(OrderStatus::Delivered, $delivered->status);
        $this->assertSame('2026-10-22 11:00:00', $delivered->delivered_at->toDateTimeString());
        $this->assertSame(90.0, (float) $line->fresh()->current_level);
        $this->assertSame($salesBefore, DB::table('sales_transactions')->count());
    }

    public function test_draft_cannot_be_dispatched_or_delivered_directly(): void
    {
        $product = $this->product('1L', '45.00');
        $draft = $this->draft([$product->id => 1]);

        $this->expectException(InvalidOrderTransitionException::class);
        $this->orders()->dispatch($draft, $this->administrator);
    }

    private function orders(): OrderService
    {
        return app(OrderService::class);
    }

    /**
     * @param  array<int, int>  $quantities
     */
    private function draft(array $quantities): Order
    {
        return $this->orders()->saveDraft(null, $this->customer, $quantities, null, null, $this->administrator);
    }

    /**
     * @param  array<int, int>  $quantities
     */
    private function confirmedOrder(array $quantities): Order
    {
        return $this->orders()->confirm($this->draft($quantities), $this->administrator);
    }

    private function product(string $sku, ?string $price): Product
    {
        return Product::factory()->create(['unit_type' => $sku, 'name' => "Bottled Water {$sku}", 'unit_price_kes' => $price]);
    }

    private function stockLine(Product $product, int $level, string $batchDate, string $location = 'Bay A1'): WarehouseStock
    {
        $batch = ProductionBatch::query()->create([
            'user_id' => $this->administrator->id,
            'batch_date' => $batchDate,
            'raw_water_volume' => 100,
            'bottled_volume' => 100,
            'status' => 'completed',
        ]);

        return WarehouseStock::query()->create([
            'batch_id' => $batch->id,
            'product_id' => $product->id,
            'current_level' => $level,
            'warehouse_location' => $location,
        ]);
    }

    private function userWithRole(string $roleSlug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $roleSlug)->valueOrFail('id')]);
    }
}
