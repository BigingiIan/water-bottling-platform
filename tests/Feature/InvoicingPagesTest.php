<?php

namespace Tests\Feature;

use App\Livewire\InvoiceDetail;
use App\Livewire\InvoicesPage;
use App\Livewire\OrderDetail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\InvoiceStatus;
use App\Services\Orders\OrderStatus;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class InvoicingPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        $this->administrator = $this->userWithRole('administrator');
    }

    public function test_roles_without_invoicing_access_are_forbidden_everywhere(): void
    {
        $invoice = $this->issuedInvoice();
        $this->actingAs($this->userWithRole('inventory-staff'));

        $this->get(route('invoices.index'))->assertForbidden();
        $this->get(route('invoices.show', $invoice))->assertForbidden();
        $this->get(route('invoices.pdf', $invoice))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('invoices.index'));
    }

    public function test_view_access_is_read_only_and_write_methods_are_refused(): void
    {
        $invoice = $this->issuedInvoice();
        DB::table('role_module_permissions')
            ->where('role_id', Role::query()->where('slug', 'production-manager')->valueOrFail('id'))
            ->where('module_id', Module::query()->where('slug', 'invoicing')->valueOrFail('id'))
            ->update(['access_level' => 'view']);
        $this->actingAs($this->userWithRole('production-manager'));

        $this->get(route('invoices.index'))->assertOk()->assertSee('INV-2026-0001');
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSeeText('Download PDF')->assertDontSeeText('Record payment')->assertDontSeeText('Void');
        $this->get(route('invoices.pdf', $invoice))->assertOk();

        Livewire::test(InvoiceDetail::class, ['invoice' => $invoice])->call('openPaymentModal')->assertForbidden();
        Livewire::test(InvoiceDetail::class, ['invoice' => $invoice])->set('voidReason', 'No')->call('saveVoid')->assertForbidden();
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    public function test_full_access_creates_issues_takes_payment_and_voids_from_the_pages(): void
    {
        $order = $this->dispatchedOrder();
        $this->actingAs($this->administrator);

        $this->get(route('orders.show', $order))->assertOk()->assertSeeText('Create invoice');
        Livewire::test(OrderDetail::class, ['order' => $order])->call('createInvoice')->assertRedirect(route('invoices.show', Invoice::query()->sole()));
        $invoice = Invoice::query()->sole();

        Livewire::test(InvoiceDetail::class, ['invoice' => $invoice])
            ->assertSeeText('Issue invoice')
            ->call('issue')
            ->assertHasNoErrors()
            ->assertSeeText('INV-2026-0001');
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);

        Livewire::test(InvoiceDetail::class, ['invoice' => $invoice])
            ->call('openPaymentModal')
            ->assertSet('paymentAmount', '232.00')
            ->set('paymentAmount', '300.00')
            ->set('paymentReference', 'SJK4H7Q2LP')
            ->call('savePayment')
            ->assertHasErrors(['paymentAmount'])
            ->set('paymentAmount', '100.00')
            ->call('savePayment')
            ->assertHasNoErrors()
            ->assertSet('paymentModalOpen', false);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        $payment = $invoice->payments()->sole();
        Livewire::test(InvoiceDetail::class, ['invoice' => $invoice])
            ->call('openReversalModal', $payment->id)
            ->call('saveReversal')
            ->assertHasErrors(['reversalReason'])
            ->set('reversalReason', 'Customer disputed')
            ->call('saveReversal')
            ->assertHasNoErrors();
        $this->assertNotNull($payment->fresh()->reversed_at);

        Livewire::test(InvoiceDetail::class, ['invoice' => $invoice])
            ->call('openVoidModal')
            ->set('voidReason', 'Wrong delivery address')
            ->call('saveVoid')
            ->assertHasNoErrors()
            ->assertSeeText('Wrong delivery address');
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
        $this->get(route('orders.show', $order))->assertSeeText('Create invoice');
    }

    public function test_orders_that_are_not_dispatched_offer_no_invoice_button(): void
    {
        $order = $this->dispatchedOrder();
        $order->update(['status' => OrderStatus::Confirmed, 'dispatched_at' => null]);
        $this->actingAs($this->administrator);

        $this->get(route('orders.show', $order))->assertOk()->assertDontSeeText('Create invoice');
        Livewire::test(OrderDetail::class, ['order' => $order])->call('createInvoice')->assertSeeText('Only dispatched or delivered orders can be invoiced');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_list_filters_by_status_customer_and_overdue(): void
    {
        $overdue = $this->issuedInvoice(Customer::factory()->create(['name' => 'Naivas Thika', 'payment_terms_days' => 7]));
        $current = $this->issuedInvoice(Customer::factory()->create(['name' => 'Quickmart Kilimani', 'payment_terms_days' => 30]));
        $this->travelTo('2026-11-05 09:00:00');
        $this->actingAs($this->administrator);

        Livewire::test(InvoicesPage::class)
            ->assertSee($overdue->invoice_number)->assertSee($current->invoice_number)->assertSeeText('Overdue')
            ->set('overdueOnly', true)
            ->assertSee($overdue->invoice_number)->assertDontSee($current->invoice_number)
            ->set('overdueOnly', false)
            ->set('customerFilter', (string) $current->customer_id)
            ->assertDontSee($overdue->invoice_number)->assertSee($current->invoice_number)
            ->set('customerFilter', '')
            ->set('statusFilter', 'paid')
            ->assertDontSee($overdue->invoice_number)->assertDontSee($current->invoice_number);
    }

    public function test_pdf_download_and_the_internal_invoice_label(): void
    {
        $invoice = $this->issuedInvoice();
        $this->actingAs($this->administrator);

        $this->get(route('invoices.show', $invoice))->assertOk()->assertSeeText('Internal invoice, not an eTIMS tax invoice.');

        $response = $this->get(route('invoices.pdf', $invoice));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('INV-2026-0001', (string) $response->headers->get('content-disposition'));
    }

    private function issuedInvoice(?Customer $customer = null): Invoice
    {
        $invoices = app(InvoiceService::class);

        return $invoices->issue($invoices->createDraftFromOrder($this->dispatchedOrder($customer), $this->administrator), $this->administrator);
    }

    private function dispatchedOrder(?Customer $customer = null): Order
    {
        $order = Order::factory()->create([
            'customer_id' => ($customer ?? Customer::factory()->create())->id,
            'status' => OrderStatus::Dispatched,
            'dispatched_at' => now(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 2,
            'unit_price' => '100.00',
            'line_total' => '200.00',
        ]);

        return $order;
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $slug)->valueOrFail('id')]);
    }
}
