<?php

namespace Tests\Feature;

use App\Exceptions\Invoicing\InvoicingException;
use App\Exceptions\Invoicing\OverpaymentException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\InvoiceStatus;
use App\Services\Invoicing\PaymentService;
use App\Services\Orders\OrderStatus;
use App\Services\SettingsService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoicingTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        $this->administrator = User::factory()->create(['role_id' => Role::query()->where('slug', 'administrator')->valueOrFail('id')]);
    }

    /**
     * @return array<string, array{OrderStatus}>
     */
    public static function uninvoiceableStatuses(): array
    {
        return [
            'draft' => [OrderStatus::Draft],
            'confirmed' => [OrderStatus::Confirmed],
            'cancelled' => [OrderStatus::Cancelled],
        ];
    }

    #[DataProvider('uninvoiceableStatuses')]
    public function test_only_dispatched_or_delivered_orders_can_be_invoiced(OrderStatus $status): void
    {
        $order = $this->order(status: $status);

        $this->expectException(InvoicingException::class);
        $this->expectExceptionMessage('Only dispatched or delivered orders can be invoiced');

        $this->invoices()->createDraftFromOrder($order, $this->administrator);
    }

    public function test_draft_copies_order_lines_and_vat_settings_without_a_number(): void
    {
        $order = $this->order(lines: [['45.00', 10], ['220.00', 3]], status: OrderStatus::Delivered);

        $draft = $this->invoices()->createDraftFromOrder($order, $this->administrator);

        $this->assertSame(InvoiceStatus::Draft, $draft->status);
        $this->assertNull($draft->invoice_number);
        $this->assertSame(['450.00', '660.00'], $draft->items->pluck('line_total')->all());
        $this->assertSame('1110.00', $draft->subtotal);
        $this->assertSame('177.60', $draft->vat_amount);
        $this->assertSame('1287.60', $draft->total);
        $this->assertSame('16.00', $draft->vat_rate);
    }

    public function test_prices_include_vat_setting_extracts_vat(): void
    {
        app(SettingsService::class)->set('invoice.prices_include_vat', true, $this->administrator);

        $draft = $this->invoices()->createDraftFromOrder($this->order(lines: [['100.00', 1]]), $this->administrator);

        $this->assertSame(['86.21', '13.79', '100.00'], [$draft->subtotal, $draft->vat_amount, $draft->total]);
    }

    public function test_an_order_has_only_one_live_invoice(): void
    {
        $order = $this->order();
        $this->invoices()->createDraftFromOrder($order, $this->administrator);

        $this->expectException(InvoicingException::class);
        $this->expectExceptionMessage('already has an invoice');

        $this->invoices()->createDraftFromOrder($order, $this->administrator);
    }

    public function test_issue_assigns_the_number_and_due_date_from_payment_terms(): void
    {
        $order = $this->order(customer: Customer::factory()->create(['payment_terms_days' => 45]));

        $invoice = $this->issued($order);

        $this->assertSame('INV-2026-0001', $invoice->invoice_number);
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('2026-10-22', $invoice->issue_date->toDateString());
        $this->assertSame('2026-12-06', $invoice->due_date->toDateString());
        $this->assertDatabaseHas('audit_log', ['action' => 'invoices.issued', 'user_id' => $this->administrator->id]);
    }

    public function test_discarded_drafts_never_consume_a_number(): void
    {
        $this->invoices()->discardDraft($this->invoices()->createDraftFromOrder($this->order(), $this->administrator), $this->administrator);

        $this->assertSame('INV-2026-0001', $this->issued($this->order())->invoice_number);
    }

    public function test_issued_invoices_are_immutable(): void
    {
        $invoice = $this->issued($this->order());

        try {
            $invoice->update(['total' => '1.00']);
            $this->fail('Changing an issued total should be refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cannot be changed', $exception->getMessage());
        }

        try {
            $invoice->items()->first()->update(['quantity' => 99]);
            $this->fail('Changing an issued line should be refused.');
        } catch (LogicException) {
        }

        $this->expectException(InvoicingException::class);
        $this->invoices()->issue($invoice->fresh(), $this->administrator);
    }

    public function test_void_needs_a_reason_keeps_the_number_and_allows_reissue(): void
    {
        $order = $this->order();
        $first = $this->issued($order);

        try {
            $this->invoices()->void($first, '  ', $this->administrator);
            $this->fail('A blank reason should be refused.');
        } catch (InvoicingException $exception) {
            $this->assertSame('A reason is required to void an invoice.', $exception->getMessage());
        }

        $voided = $this->invoices()->void($first, 'Wrong customer address', $this->administrator);
        $replacement = $this->issued($order);

        $this->assertSame(InvoiceStatus::Void, $voided->status);
        $this->assertSame('INV-2026-0001', $voided->invoice_number);
        $this->assertSame('Wrong customer address', $voided->void_reason);
        $this->assertSame('INV-2026-0002', $replacement->invoice_number);
        $this->assertDatabaseHas('audit_log', ['action' => 'invoices.voided']);
    }

    public function test_void_is_refused_while_payments_are_live(): void
    {
        $invoice = $this->issued($this->order());
        $payment = $this->payments()->record($invoice, '100.00', 'cash', null, now(), null, $this->administrator);

        try {
            $this->invoices()->void($invoice, 'Duplicate', $this->administrator);
            $this->fail('Void should require reversing payments first.');
        } catch (InvoicingException $exception) {
            $this->assertStringContainsString('Reverse the payments', $exception->getMessage());
        }

        $this->payments()->reverse($payment, 'Recorded on the wrong invoice', $this->administrator);
        $this->assertSame(InvoiceStatus::Void, $this->invoices()->void($invoice, 'Duplicate', $this->administrator)->status);
    }

    public function test_overpayment_is_rejected(): void
    {
        $invoice = $this->issued($this->order(lines: [['100.00', 1]]));

        $this->expectException(OverpaymentException::class);
        $this->expectExceptionMessage('A payment of KES 116.01 is more than the outstanding balance of KES 116.00.');

        $this->payments()->record($invoice, '116.01', 'cash', null, now(), null, $this->administrator);
    }

    public function test_partial_then_full_payment_updates_status_and_balance(): void
    {
        $invoice = $this->issued($this->order(lines: [['1000.00', 1]]));

        $this->payments()->record($invoice, '500.00', 'mpesa', 'sjk4h7q2lp', now(), null, $this->administrator);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame('660.00', $invoice->balance());

        $this->payments()->record($invoice, '660.00', 'bank_transfer', 'FT2629', now(), null, $this->administrator);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame('0.00', $invoice->balance());
        $this->assertSame('SJK4H7Q2LP', $invoice->payments()->first()->reference);
        $this->assertSame(2, DB::table('audit_log')->where('action', 'payments.recorded')->count());

        $this->expectException(InvoicingException::class);
        $this->payments()->record($invoice, '1.00', 'cash', null, now(), null, $this->administrator);
    }

    public function test_reversal_keeps_the_row_and_restores_the_balance(): void
    {
        $invoice = $this->issued($this->order(lines: [['100.00', 1]]));
        $payment = $this->payments()->record($invoice, '116.00', 'mpesa', 'QWE123RTY', now(), null, $this->administrator);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        $reversed = $this->payments()->reverse($payment, 'Customer paid the wrong till', $this->administrator);

        $this->assertNotNull($reversed->reversed_at);
        $this->assertSame('QWE123RTY', $reversed->reference);
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame('116.00', $invoice->fresh()->balance());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('audit_log', ['action' => 'payments.reversed']);

        $this->expectException(InvoicingException::class);
        $this->payments()->reverse($reversed, 'Again', $this->administrator);
    }

    public function test_payment_references_are_unique_per_method_while_live(): void
    {
        $first = $this->issued($this->order(lines: [['1000.00', 1]]));
        $second = $this->issued($this->order(lines: [['1000.00', 1]]));
        $payment = $this->payments()->record($first, '100.00', 'mpesa', 'SAME123', now(), null, $this->administrator);
        $this->payments()->record($second, '100.00', 'bank_transfer', 'SAME123', now(), null, $this->administrator);

        try {
            $this->payments()->record($second, '100.00', 'mpesa', 'same123', now(), null, $this->administrator);
            $this->fail('A live M-Pesa code may only be recorded once.');
        } catch (InvoicingException $exception) {
            $this->assertSame('The M-Pesa reference SAME123 has already been recorded.', $exception->getMessage());
        }

        $this->payments()->reverse($payment, 'Wrong invoice', $this->administrator);
        $this->payments()->record($second, '100.00', 'mpesa', 'SAME123', now(), null, $this->administrator);

        $this->assertSame('200.00', $second->fresh()->amount_paid);
    }

    public function test_overdue_is_computed_from_the_due_date(): void
    {
        $invoice = $this->issued($this->order(customer: Customer::factory()->create(['payment_terms_days' => 7])));

        $this->assertFalse($invoice->isOverdue(Carbon::parse('2026-10-29')), 'Due today is not overdue.');
        $this->assertTrue($invoice->isOverdue(Carbon::parse('2026-10-30')));
        $this->assertSame(1, Invoice::query()->overdue(Carbon::parse('2026-10-30'))->count());

        $this->payments()->record($invoice, $invoice->balance(), 'cash', null, now(), null, $this->administrator);
        $this->assertFalse($invoice->fresh()->isOverdue(Carbon::parse('2027-01-01')), 'Paid invoices are never overdue.');
    }

    public function test_overdue_command_notifies_once_and_respects_the_setting(): void
    {
        $invoice = $this->issued($this->order(customer: Customer::factory()->create(['payment_terms_days' => 0])));
        $this->travelTo('2026-10-25 08:00:00');

        $this->artisan('invoices:notify-overdue')->expectsOutput('Checked 1 overdue invoice.')->assertSuccessful();
        $this->artisan('invoices:notify-overdue')->assertSuccessful();

        $notification = $this->administrator->notifications()->sole();
        $this->assertSame('invoice.overdue', $notification->data['type']);
        $this->assertSame("invoice:{$invoice->id}:overdue", $notification->data['key']);

        $notification->markAsRead();
        app(SettingsService::class)->set('notify.invoice_overdue', false, $this->administrator);
        $this->artisan('invoices:notify-overdue')->assertSuccessful();
        $this->assertSame(1, $this->administrator->notifications()->count());
    }

    public function test_large_payments_notify_invoicing_users(): void
    {
        $clerk = User::factory()->create(['role_id' => Role::query()->where('slug', 'administrator')->valueOrFail('id')]);
        app(SettingsService::class)->set('invoice.large_payment_kes', 500, $this->administrator);
        $invoice = $this->issued($this->order(lines: [['1000.00', 1]]));

        $this->payments()->record($invoice, '499.99', 'cash', null, now(), null, $this->administrator);
        $this->assertSame(0, $clerk->notifications()->count());

        $this->payments()->record($invoice, '500.00', 'cash', null, now(), null, $this->administrator);
        $this->assertSame('payment.large', $clerk->notifications()->sole()->data['type']);
    }

    private function invoices(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    private function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function issued(Order $order): Invoice
    {
        return $this->invoices()->issue($this->invoices()->createDraftFromOrder($order, $this->administrator), $this->administrator);
    }

    /**
     * @param  array<int, array{string, int}>  $lines  [unit price, quantity]
     */
    private function order(array $lines = [['100.00', 2]], OrderStatus $status = OrderStatus::Dispatched, ?Customer $customer = null): Order
    {
        $order = Order::factory()->create([
            'customer_id' => ($customer ?? Customer::factory()->create())->id,
            'status' => $status,
            'dispatched_at' => in_array($status, [OrderStatus::Dispatched, OrderStatus::Delivered], true) ? now() : null,
        ]);

        foreach ($lines as [$price, $quantity]) {
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => Product::factory()->create()->id,
                'quantity' => $quantity,
                'unit_price' => $price,
                'line_total' => bcmul($price, (string) $quantity, 2),
            ]);
        }

        return $order;
    }
}
