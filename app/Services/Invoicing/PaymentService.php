<?php

namespace App\Services\Invoicing;

use App\Exceptions\Invoicing\InvoicingException;
use App\Exceptions\Invoicing\OverpaymentException;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private SettingsService $settings,
        private NotificationService $notifications,
    ) {}

    /**
     * @throws OverpaymentException when the amount is more than the balance
     */
    public function record(Invoice $invoice, string $amount, string $method, ?string $reference, CarbonInterface $paidAt, ?string $notes, User $user): Payment
    {
        $amount = bcadd($amount, '0', 2);
        $reference = $reference === null || trim($reference) === '' ? null : strtoupper(trim($reference));

        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvoicingException('The payment amount must be greater than zero.');
        }

        if (! array_key_exists($method, Payment::METHODS)) {
            throw new InvoicingException('Choose a valid payment method.');
        }

        $payment = DB::transaction(function () use ($invoice, $amount, $method, $reference, $paidAt, $notes, $user): Payment {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $invoice->status->isOpen()) {
                throw new InvoicingException("Payments can only be recorded on issued invoices; {$invoice->displayNumber()} is {$invoice->status->label()}.");
            }

            if (bccomp($amount, $invoice->balance(), 2) > 0) {
                throw new OverpaymentException($amount, $invoice->balance());
            }

            $duplicateMessage = 'The '.Payment::METHODS[$method]." reference {$reference} has already been recorded.";

            if ($reference !== null && Payment::query()->where('method', $method)->where('active_reference', $reference)->exists()) {
                throw new InvoicingException($duplicateMessage);
            }

            // The unique index on (method, active_reference) is the backstop for two clerks racing.
            try {
                $payment = $invoice->payments()->create([
                    'amount' => $amount,
                    'method' => $method,
                    'reference' => $reference,
                    'active_reference' => $reference,
                    'paid_at' => $paidAt,
                    'recorded_by' => $user->id,
                    'notes' => $notes,
                ]);
            } catch (QueryException $exception) {
                if ($reference === null) {
                    throw $exception;
                }

                throw new InvoicingException($duplicateMessage);
            }

            $from = $invoice->status;
            $this->refreshPaidAmount($invoice);
            $this->audit($invoice, $payment, 'payments.recorded', $from, $user, ['amount' => $amount, 'method' => $method, 'reference' => $reference]);

            return $payment;
        });

        $threshold = bcadd((string) $this->settings->get('invoice.large_payment_kes', 100000), '0', 2);

        if (bccomp($threshold, '0', 2) > 0 && bccomp($amount, $threshold, 2) >= 0) {
            $this->notifications->largePaymentRecorded($payment->load('invoice.customer'), $user);
        }

        return $payment;
    }

    /**
     * The payment row stays for the record; it stops counting towards the invoice.
     */
    public function reverse(Payment $payment, string $reason, User $user): Payment
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvoicingException('A reason is required to reverse a payment.');
        }

        return DB::transaction(function () use ($payment, $reason, $user): Payment {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();

            if ($payment->isReversed()) {
                throw new InvoicingException('This payment has already been reversed.');
            }

            if ($invoice->status === InvoiceStatus::Void) {
                throw new InvoicingException('Payments on a voided invoice cannot be reversed.');
            }

            $payment->update([
                'active_reference' => null,
                'reversed_at' => now(),
                'reversed_by' => $user->id,
                'reversal_reason' => $reason,
            ]);

            $from = $invoice->status;
            $this->refreshPaidAmount($invoice);
            $this->audit($invoice, $payment, 'payments.reversed', $from, $user, ['amount' => (string) $payment->amount, 'reason' => $reason]);

            return $payment->refresh();
        });
    }

    /**
     * amount_paid is always the sum of live payments; status follows it.
     */
    private function refreshPaidAmount(Invoice $invoice): void
    {
        $paid = $invoice->payments()->live()->pluck('amount')
            ->reduce(fn (string $sum, mixed $amount): string => bcadd($sum, (string) $amount, 2), '0.00');

        $invoice->update([
            'amount_paid' => $paid,
            'status' => match (true) {
                bccomp($paid, (string) $invoice->total, 2) >= 0 => InvoiceStatus::Paid,
                bccomp($paid, '0', 2) > 0 => InvoiceStatus::PartiallyPaid,
                default => InvoiceStatus::Issued,
            },
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function audit(Invoice $invoice, Payment $payment, string $action, InvoiceStatus $from, User $user, array $details): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'table_name' => 'payments',
            'old_values' => ['invoice_id' => $invoice->id, 'status' => $from->value],
            'new_values' => ['invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'invoice_number' => $invoice->invoice_number, 'status' => $invoice->status->value, 'amount_paid' => (string) $invoice->amount_paid, ...$details],
        ]);
    }
}
