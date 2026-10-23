<?php

namespace App\Services\Invoicing;

use App\Exceptions\Invoicing\InvoicingException;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use App\Services\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private InvoiceNumberGenerator $numbers,
        private InvoiceCalculator $calculator,
        private SettingsService $settings,
    ) {}

    /**
     * A draft copies the order's confirmed lines and today's VAT settings; it gets a number when issued.
     */
    public function createDraftFromOrder(Order $order, User $user): Invoice
    {
        return DB::transaction(function () use ($order, $user): Invoice {
            $order = Order::query()->with(['items.product', 'customer'])->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, [OrderStatus::Dispatched, OrderStatus::Delivered], true)) {
                throw new InvoicingException("Only dispatched or delivered orders can be invoiced; {$order->order_number} is {$order->status->value}.");
            }

            if (Invoice::query()->where('orders_invoice_key', $order->id)->exists()) {
                throw new InvoicingException("{$order->order_number} already has an invoice. Void it first to invoice the order again.");
            }

            $vatRate = bcadd((string) $this->settings->get('invoice.vat_rate', 16.0), '0', 2);
            $pricesIncludeVat = (bool) $this->settings->get('invoice.prices_include_vat', false);
            $lineTotals = $order->items->map(fn (OrderItem $item): string => (string) $item->line_total)->all();
            $totals = $this->calculator->totals($lineTotals, $vatRate, $pricesIncludeVat);

            try {
                $invoice = Invoice::query()->create([
                    'order_id' => $order->id,
                    'orders_invoice_key' => $order->id,
                    'customer_id' => $order->customer_id,
                    'status' => InvoiceStatus::Draft,
                    'prices_include_vat' => $pricesIncludeVat,
                    'vat_rate' => $vatRate,
                    'subtotal' => $totals['subtotal'],
                    'vat_amount' => $totals['vatAmount'],
                    'total' => $totals['total'],
                    'created_by' => $user->id,
                ]);
            } catch (QueryException) {
                throw new InvoicingException("{$order->order_number} already has an invoice.");
            }

            foreach ($order->items as $item) {
                $invoice->items()->create([
                    'product_id' => $item->product_id,
                    'description' => "{$item->product->name} ({$item->product->unit_type})",
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                ]);
            }

            $this->audit($invoice, 'invoices.drafted', null, $user, ['order_number' => $order->order_number]);

            return $invoice->load('items');
        });
    }

    /**
     * Freezes the invoice: assigns the next number, the issue date and the due date from payment terms.
     */
    public function issue(Invoice $invoice, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $user): Invoice {
            $invoice = $this->lock($invoice);

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new InvoicingException("{$invoice->displayNumber()} has already been issued.");
            }

            $totals = $this->calculator->totals(
                $invoice->items->map(fn ($item): string => (string) $item->line_total)->all(),
                (string) $invoice->vat_rate,
                $invoice->prices_include_vat,
            );
            $issueDate = today();

            $invoice->update([
                'invoice_number' => $this->numbers->next((int) $issueDate->year),
                'status' => InvoiceStatus::Issued,
                'issue_date' => $issueDate,
                'due_date' => $issueDate->copy()->addDays((int) $invoice->customer->payment_terms_days),
                'subtotal' => $totals['subtotal'],
                'vat_amount' => $totals['vatAmount'],
                'total' => $totals['total'],
                'issued_at' => now(),
                'issued_by' => $user->id,
            ]);
            $this->audit($invoice, 'invoices.issued', InvoiceStatus::Draft, $user, ['total' => $totals['total']]);

            return $invoice->refresh();
        });
    }

    /**
     * Voids an issued invoice. The number stays used; the order can be invoiced again.
     */
    public function void(Invoice $invoice, string $reason, User $user): Invoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvoicingException('A reason is required to void an invoice.');
        }

        return DB::transaction(function () use ($invoice, $reason, $user): Invoice {
            $invoice = $this->lock($invoice);
            $from = $invoice->status;

            if (! $from->isOpen()) {
                throw new InvoicingException("A {$from->label()} invoice cannot be voided.");
            }

            if ($invoice->payments()->live()->exists()) {
                throw new InvoicingException('Reverse the payments on this invoice before voiding it.');
            }

            $invoice->update([
                'status' => InvoiceStatus::Void,
                'orders_invoice_key' => null,
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by' => $user->id,
            ]);
            $this->audit($invoice, 'invoices.voided', $from, $user, ['reason' => $reason]);

            return $invoice->refresh();
        });
    }

    public function discardDraft(Invoice $invoice, User $user): void
    {
        DB::transaction(function () use ($invoice, $user): void {
            $invoice = $this->lock($invoice);

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new InvoicingException('Only draft invoices can be discarded.');
            }

            $this->audit($invoice, 'invoices.discarded', InvoiceStatus::Draft, $user);
            $invoice->items()->delete();
            $invoice->delete();
        });
    }

    private function lock(Invoice $invoice): Invoice
    {
        return Invoice::query()->with(['items', 'customer'])->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function audit(Invoice $invoice, string $action, ?InvoiceStatus $from, User $user, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'table_name' => 'invoices',
            'old_values' => ['invoice_id' => $invoice->id, 'status' => $from?->value],
            'new_values' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'status' => $invoice->status->value, ...$details],
        ]);
    }
}
