@use('App\Services\Invoicing\InvoiceStatus')

@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $money = fn ($value): string => \App\Services\Orders\Money::format((string) $value);
    $inputClasses = 'w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg';
    $secondaryButton = 'inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted';
    $primaryButton = 'inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60';
    $thClasses = 'border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted';
    $historyLabels = [
        'invoices.drafted' => 'Draft created from the order',
        'invoices.issued' => 'Issued',
        'invoices.voided' => 'Voided',
        'payments.recorded' => 'Payment recorded',
        'payments.reversed' => 'Payment reversed',
    ];
@endphp

<div class="flex flex-col gap-6" x-data="{ toast: false, toastMessage: '' }" x-on:invoice-toast.window="toastMessage = $event.detail.message; toast = true; setTimeout(() => toast = false, 4000)">
    <div x-show="toast" x-transition role="status" style="display: none" class="fixed right-4 top-4 z-40 rounded-md border border-success/35 bg-success-soft px-4 py-2.5 text-sm font-medium text-success-ink shadow-lg print:hidden">
        <span x-text="toastMessage"></span>
    </div>

    <x-page-header help="invoicing" class="print:hidden" :title="$invoice->displayNumber()" :subtitle="$invoice->customer->name.' · from order '.$invoice->order->order_number">
        <x-slot:actions>
            <x-invoice-status-badge :invoice="$invoice" class="mr-1" />
            <a href="{{ route('invoices.index') }}" class="{{ $secondaryButton }}"><x-heroicon-o-arrow-left class="size-4" aria-hidden="true" />All invoices</a>
            <button type="button" onclick="window.print()" class="{{ $secondaryButton }}"><x-heroicon-o-printer class="size-4" aria-hidden="true" />Print</button>
            <a href="{{ route('invoices.pdf', $invoice) }}" class="{{ $secondaryButton }}"><x-heroicon-o-arrow-down-tray class="size-4" aria-hidden="true" />Download PDF</a>
            @if ($canWrite)
                @if ($invoice->status === InvoiceStatus::Draft)
                    <button type="button" wire:click="discard" wire:confirm="Discard this draft invoice?" class="{{ $secondaryButton }} text-danger-ink"><x-heroicon-o-trash class="size-4" aria-hidden="true" />Discard draft</button>
                    <button type="button" wire:click="issue" wire:confirm="Issue this invoice? Issued invoices cannot be edited." wire:loading.attr="disabled" class="{{ $primaryButton }}"><x-heroicon-o-check-circle class="size-4" aria-hidden="true" />Issue invoice</button>
                @elseif ($invoice->status->isOpen())
                    <button type="button" wire:click="openVoidModal" class="{{ $secondaryButton }} text-danger-ink"><x-heroicon-o-no-symbol class="size-4" aria-hidden="true" />Void</button>
                    <button type="button" wire:click="openPaymentModal" class="{{ $primaryButton }}"><x-heroicon-o-banknotes class="size-4" aria-hidden="true" />Record payment</button>
                @endif
            @endif
        </x-slot:actions>
    </x-page-header>

    @error('invoiceAction')
        <p role="alert" class="flex items-center gap-2 rounded-md border border-danger/35 bg-danger-soft px-4 py-3 text-sm text-danger-ink print:hidden">
            <x-heroicon-o-exclamation-triangle class="size-5 shrink-0" aria-hidden="true" />{{ $message }}
        </p>
    @enderror

    @if ($invoice->status === InvoiceStatus::Void)
        <p role="status" class="rounded-md border border-border-strong bg-surface-muted px-4 py-3 text-sm text-fg-soft print:hidden">
            Voided {{ $invoice->voided_at ? $settingsDate->formatDateTime($invoice->voided_at) : '' }}{{ $invoice->voider ? ' by '.$invoice->voider->name : '' }}: {{ $invoice->void_reason }}
            @if ($replacement)
                · Replaced by <a href="{{ route('invoices.show', $replacement) }}" class="font-semibold text-accent-ink hover:underline">{{ $replacement->displayNumber() }}</a>
            @endif
        </p>
    @elseif ($invoice->isOverdue())
        <p role="status" class="flex items-center gap-2 rounded-md border border-danger/35 bg-danger-soft px-4 py-3 text-sm font-medium text-danger-ink print:hidden">
            <x-heroicon-o-exclamation-triangle class="size-5 shrink-0" aria-hidden="true" />
            Overdue: {{ $money($invoice->balance()) }} was due {{ $settingsDate->formatDate($invoice->due_date) }}.
        </p>
    @endif

    <div class="grid items-start gap-[18px] xl:grid-cols-[1.6fr_1fr]">
        <article aria-label="Invoice document" class="overflow-hidden rounded-lg border border-border shadow-sm print:border-0 print:shadow-none">
            <div class="inv-screen-paper">
                @include('invoices.document')
            </div>
        </article>

        <div class="flex min-w-0 flex-col gap-[18px] print:hidden">
            <section aria-labelledby="payments-heading" class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
                <div class="flex items-baseline justify-between gap-2 border-b border-border px-[18px] py-3.5">
                    <h2 id="payments-heading" class="text-sm font-bold text-fg">Payments</h2>
                    @if ($invoice->status !== InvoiceStatus::Draft && $invoice->status !== InvoiceStatus::Void)
                        <p class="text-xs text-muted">Balance <span class="font-bold text-fg">{{ $money($invoice->balance()) }}</span></p>
                    @endif
                </div>
                <ul class="divide-y divide-border">
                    @forelse ($payments as $payment)
                        <li wire:key="payment-{{ $payment->id }}" @class(['px-[18px] py-3 text-[13px]', 'bg-surface-muted' => $payment->isReversed()])>
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p @class(['font-semibold tabular-nums text-fg', 'line-through text-muted' => $payment->isReversed()])>{{ $money($payment->amount) }}</p>
                                    <p class="text-xs text-muted">{{ $payment->methodLabel() }}{{ $payment->reference ? ' · '.$payment->reference : '' }} · {{ $settingsDate->formatDate($payment->paid_at) }}{{ $payment->recorder ? ' · '.$payment->recorder->name : '' }}</p>
                                    @if ($payment->notes)<p class="mt-0.5 text-xs text-muted">{{ $payment->notes }}</p>@endif
                                    @if ($payment->isReversed())
                                        <p class="mt-1 text-xs font-medium text-danger-ink">Reversed {{ $settingsDate->formatDate($payment->reversed_at) }}{{ $payment->reverser ? ' by '.$payment->reverser->name : '' }}: {{ $payment->reversal_reason }}</p>
                                    @endif
                                </div>
                                @if ($canWrite && ! $payment->isReversed() && $invoice->status !== InvoiceStatus::Void)
                                    <button type="button" wire:click="openReversalModal({{ $payment->id }})" class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-accent-ink hover:text-accent-ink"><x-heroicon-o-arrow-uturn-left class="size-3.5" aria-hidden="true" />Reverse</button>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="px-[18px] py-6 text-center text-sm text-muted">{{ $invoice->status === InvoiceStatus::Draft ? 'Issue the invoice before recording payments.' : 'No payments recorded yet.' }}</li>
                    @endforelse
                </ul>
            </section>

            <section aria-labelledby="invoice-history-heading" class="rounded-lg border border-border bg-surface p-[18px] shadow-sm">
                <h2 id="invoice-history-heading" class="text-sm font-bold text-fg">History</h2>
                <ol class="mt-3 space-y-3">
                    @foreach ($history as $entry)
                        <li wire:key="history-{{ $entry->id }}" class="text-[13px]">
                            <p class="font-semibold text-fg">{{ $historyLabels[$entry->action] ?? $entry->action }}{{ isset($entry->new_values['amount']) ? ' · '.$money($entry->new_values['amount']) : '' }}</p>
                            <p class="text-xs text-muted">{{ $settingsDate->formatDateTime($entry->timestamp) }} · {{ $entry->user?->name ?? 'System' }}{{ isset($entry->new_values['reason']) ? ' · '.$entry->new_values['reason'] : '' }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>
    </div>

    @if ($paymentModalOpen)
        <x-modal-dialog id="record-payment" title="Record payment" close="closePaymentModal">
            <p class="text-[13px] text-fg-soft">Outstanding balance: <span class="font-semibold text-fg">{{ $money($invoice->balance()) }}</span>. Payments above the balance are rejected.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="payment-amount" class="mb-1.5 block text-xs font-semibold text-fg-soft">Amount (KES)</label>
                    <input id="payment-amount" type="number" min="0.01" step="0.01" wire:model="paymentAmount" class="{{ $inputClasses }} tabular-nums">
                    @error('paymentAmount') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="payment-method" class="mb-1.5 block text-xs font-semibold text-fg-soft">Method</label>
                    <select id="payment-method" wire:model.live="paymentMethod" class="{{ $inputClasses }}">
                        @foreach ($methods as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="payment-reference" class="mb-1.5 block text-xs font-semibold text-fg-soft">{{ $paymentMethod === 'mpesa' ? 'M-Pesa code' : 'Reference (optional)' }}</label>
                    <input id="payment-reference" type="text" wire:model="paymentReference" placeholder="{{ $paymentMethod === 'mpesa' ? 'e.g. SJK4H7Q2LP' : 'Bank or cheque reference' }}" class="{{ $inputClasses }} font-mono uppercase">
                    @error('paymentReference') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="payment-date" class="mb-1.5 block text-xs font-semibold text-fg-soft">Paid on</label>
                    <input id="payment-date" type="date" wire:model="paymentDate" class="{{ $inputClasses }}">
                    @error('paymentDate') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="payment-notes" class="mb-1.5 block text-xs font-semibold text-fg-soft">Notes</label>
                <input id="payment-notes" type="text" wire:model="paymentNotes" class="{{ $inputClasses }}">
            </div>
            <x-slot:footer>
                <button type="button" wire:click="closePaymentModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft">Cancel</button>
                <button type="button" wire:click="savePayment" wire:loading.attr="disabled" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60">Record payment</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif

    @if ($voidModalOpen)
        <x-modal-dialog id="void-invoice" title="Void invoice" close="closeVoidModal">
            <p class="text-[13px] text-fg-soft">{{ $invoice->displayNumber() }} keeps its number and stays on record as void. You can then create a corrected invoice from order {{ $invoice->order->order_number }}.</p>
            <div>
                <label for="void-reason" class="mb-1.5 block text-xs font-semibold text-fg-soft">Reason</label>
                <textarea id="void-reason" rows="3" wire:model="voidReason" class="{{ $inputClasses }}" placeholder="What was wrong with this invoice"></textarea>
                @error('voidReason') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                @error('invoiceAction') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
            </div>
            <x-slot:footer>
                <button type="button" wire:click="closeVoidModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft">Cancel</button>
                <button type="button" wire:click="saveVoid" class="rounded-md bg-danger px-3.5 py-2 text-[13px] font-semibold text-on-danger hover:bg-danger-hover">Void invoice</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif

    @if ($reversingPayment)
        <x-modal-dialog id="reverse-payment" title="Reverse payment" close="closeReversalModal">
            <p class="text-[13px] text-fg-soft">{{ $money($reversingPayment->amount) }} by {{ $reversingPayment->methodLabel() }}{{ $reversingPayment->reference ? ' ('.$reversingPayment->reference.')' : '' }} will stay on record as reversed and stop counting towards this invoice.</p>
            <div>
                <label for="reversal-reason" class="mb-1.5 block text-xs font-semibold text-fg-soft">Reason</label>
                <textarea id="reversal-reason" rows="3" wire:model="reversalReason" class="{{ $inputClasses }}" placeholder="For example: recorded against the wrong invoice"></textarea>
                @error('reversalReason') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
            </div>
            <x-slot:footer>
                <button type="button" wire:click="closeReversalModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft">Cancel</button>
                <button type="button" wire:click="saveReversal" class="rounded-md bg-danger px-3.5 py-2 text-[13px] font-semibold text-on-danger hover:bg-danger-hover">Reverse payment</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif
</div>
