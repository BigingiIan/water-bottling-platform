<?php

namespace Tests\Feature;

use App\Livewire\OrderDetail;
use App\Livewire\OrderEditor;
use App\Livewire\OrdersPage;
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
use Livewire\Livewire;
use Tests\TestCase;

class OrdersPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
    }

    public function test_roles_without_orders_access_are_forbidden_everywhere(): void
    {
        $order = $this->draftFor($this->userWithRole('administrator'));
        $this->actingAs($this->userWithRole('production-staff'));

        $this->get(route('orders.index'))->assertForbidden();
        $this->get(route('orders.create'))->assertForbidden();
        $this->get(route('orders.show', $order))->assertForbidden();
        $this->get(route('orders.edit', $order))->assertForbidden();
    }

    public function test_view_access_is_read_only_and_write_methods_are_refused(): void
    {
        $order = $this->draftFor($this->userWithRole('administrator'));
        $this->actingAs($this->userWithRole('production-manager'));

        $this->get(route('orders.index'))->assertOk()->assertSee($order->order_number)->assertDontSee('New order');
        $this->get(route('orders.show', $order))->assertOk()->assertDontSee('Cancel order')->assertDontSee('>Confirm<', false);
        $this->get(route('orders.create'))->assertForbidden();

        Livewire::test(OrdersPage::class)->call('openCustomerModal')->assertForbidden();
        Livewire::test(OrderDetail::class, ['order' => $order])->call('confirm')->assertForbidden();
        Livewire::test(OrderDetail::class, ['order' => $order])->call('cancel')->assertForbidden();
        $this->assertSame(OrderStatus::Draft, $order->fresh()->status);
    }

    public function test_full_access_creates_confirms_dispatches_and_delivers_from_the_pages(): void
    {
        $inventoryStaff = $this->userWithRole('inventory-staff');
        $customer = Customer::factory()->create(['name' => 'Carrefour Junction']);
        $product = Product::factory()->create(['unit_type' => '1L', 'name' => 'Bottled Water 1L', 'unit_price_kes' => '45.00']);
        $this->stockLine($product, 100, $inventoryStaff);
        $this->actingAs($inventoryStaff);

        Livewire::test(OrderEditor::class)
            ->set('customerId', (string) $customer->id)
            ->set('lines.0.product_id', (string) $product->id)
            ->set('lines.0.quantity', '12')
            ->assertSee('KES 540.00')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertRedirect(route('orders.show', Order::query()->sole()));

        $order = Order::query()->sole();
        $this->assertSame('ORD-2026-0001', $order->order_number);

        $detail = Livewire::test(OrderDetail::class, ['order' => $order])
            ->assertSee('Confirm')
            ->assertSee('Cancel order')
            ->call('confirm')
            ->assertDispatched('orders-toast', message: 'Order confirmed.')
            ->assertSee('Dispatch')
            ->call('dispatchOrder')
            ->assertDispatched('orders-toast', message: 'Order dispatched. Stock has been deducted.')
            ->assertSee('Stock allocation')
            ->assertSee('Bay A1')
            ->assertSee('Mark delivered')
            ->assertDontSee('Cancel order');

        $detail->call('deliver')->assertSee('Delivered');
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame(88.0, (float) WarehouseStock::query()->sole()->current_level);
    }

    public function test_editor_shows_per_line_shortages_when_confirm_is_blocked(): void
    {
        $administrator = $this->userWithRole('administrator');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['unit_type' => '5L', 'name' => 'Bottled Water 5L', 'unit_price_kes' => '120.00']);
        $this->stockLine($product, 10, $administrator);
        $this->actingAs($administrator);

        Livewire::test(OrderEditor::class)
            ->set('customerId', (string) $customer->id)
            ->set('lines.0.product_id', (string) $product->id)
            ->set('lines.0.quantity', '25')
            ->call('confirmOrder')
            ->assertHasErrors('confirm')
            ->assertSee('5L: need 25, only 10 available.')
            ->assertNoRedirect();

        $this->assertSame(OrderStatus::Draft, Order::query()->sole()->status);
    }

    public function test_confirmed_orders_cannot_be_edited(): void
    {
        $administrator = $this->userWithRole('administrator');
        $order = $this->draftFor($administrator, stock: 50);
        app(OrderService::class)->confirm($order, $administrator);
        $this->actingAs($administrator);

        $this->get(route('orders.edit', $order))->assertRedirect(route('orders.show', $order));
    }

    public function test_customers_tab_adds_and_edits_customers_with_payment_terms(): void
    {
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::test(OrdersPage::class)
            ->set('activeTab', 'customers')
            ->call('openCustomerModal')
            ->assertSet('customerPaymentTerms', '30')
            ->set('customerName', 'Quickmart Thika Road')
            ->set('customerContact', '+254700111222')
            ->set('customerType', 'retail')
            ->call('saveCustomer')
            ->assertHasNoErrors()
            ->assertDispatched('orders-toast', message: 'Customer added.')
            ->assertSee('Quickmart Thika Road')
            ->assertSee('30 days');

        $customer = Customer::query()->where('name', 'Quickmart Thika Road')->sole();

        Livewire::test(OrdersPage::class)
            ->set('activeTab', 'customers')
            ->call('openCustomerModal', $customer->id)
            ->assertSet('customerName', 'Quickmart Thika Road')
            ->set('customerPaymentTerms', '45')
            ->call('saveCustomer')
            ->assertDispatched('orders-toast', message: 'Customer updated.');

        $this->assertSame(45, $customer->fresh()->payment_terms_days);
    }

    public function test_list_shows_kpis_and_filters_by_status(): void
    {
        $administrator = $this->userWithRole('administrator');
        $draft = $this->draftFor($administrator, stock: 100);
        $dispatched = $this->draftFor($administrator, sku: '5L', stock: 100);
        app(OrderService::class)->dispatch(app(OrderService::class)->confirm($dispatched, $administrator), $administrator);
        $this->actingAs($administrator);

        Livewire::test(OrdersPage::class)
            ->assertSee('Open orders')
            ->assertSee('Value this month')
            ->assertSee('KES 450.00')
            ->assertSee($draft->order_number)
            ->assertSee($dispatched->order_number)
            ->set('statusFilter', 'dispatched')
            ->assertDontSee($draft->order_number)
            ->assertSee($dispatched->order_number);
    }

    private function draftFor(User $user, string $sku = '1L', int $stock = 0): Order
    {
        $product = Product::query()->firstWhere('unit_type', $sku)
            ?? Product::factory()->create(['unit_type' => $sku, 'name' => "Bottled Water {$sku}", 'unit_price_kes' => '45.00']);

        if ($stock > 0) {
            $this->stockLine($product, $stock, $user);
        }

        return app(OrderService::class)->saveDraft(null, Customer::factory()->create(), [$product->id => 10], null, null, $user);
    }

    private function stockLine(Product $product, int $level, User $user): WarehouseStock
    {
        $batch = ProductionBatch::query()->create([
            'user_id' => $user->id,
            'batch_date' => '2026-10-01',
            'raw_water_volume' => 100,
            'bottled_volume' => 100,
            'status' => 'completed',
        ]);

        return WarehouseStock::query()->create([
            'batch_id' => $batch->id,
            'product_id' => $product->id,
            'current_level' => $level,
            'warehouse_location' => 'Bay A1',
        ]);
    }

    private function userWithRole(string $roleSlug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $roleSlug)->valueOrFail('id')]);
    }
}
