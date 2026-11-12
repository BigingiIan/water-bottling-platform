@use('App\Services\Orders\Money')
@use('App\Services\Orders\OrderStatus')

@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $canSendRequests = app(\App\Services\Requests\RequestAccess::class)->canSendRequests(auth()->user());
    $thClasses = 'border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted';
    $secondaryButton = 'inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted disabled:opacity-60';
    $primaryButton = 'inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60';
    $timelineLabels = [
        'orders.created' => 'Created as draft',
        'orders.updated' => 'Draft edited',
        'confirmed' => 'Confirmed',
        'dispatched' => 'Dispatched',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];
@endphp

<div class="flex flex-col gap-6" x-data="{ toast: false, toastMessage: '' }" x-on:orders-toast.window="toastMessage = $event.detail.message; toast = true; setTimeout(() => toast = false, 4000)">
    <div x-show="toast" x-transition role="status" style="display: none" class="fixed right-4 top-4 z-40 rounded-md border border-success/35 bg-success-soft px-4 py-2.5 text-sm font-medium text-success-ink shadow-lg">
        <span x-text="toastMessage"></span>
    </div>

    <x-page-header help="orders" :title="$order->order_number" :subtitle="$order->customer->name.' · ordered '.$settingsDate->formatDate($order->order_date).($order->requested_date ? ' · requested for '.$settingsDate->formatDate($order->requested_date) : '')">
        <x-slot:actions>
            <x-order-status-badge :status="$order->status" class="mr-1" />
            <a href="{{ route('orders.index') }}" class="{{ $secondaryButton }}"><x-heroicon-o-arrow-left class="size-4" aria-hidden="true" />All orders</a>
            @if ($canSendRequests)
                <x-send-request-button related-type="order" :related-id="$order->id" />
            @endif
            @if ($liveInvoice && $canViewInvoices)
                <a href="{{ route('invoices.show', $liveInvoice) }}" class="{{ $secondaryButton }}"><x-heroicon-o-document-text class="size-4" aria-hidden="true" />View invoice {{ $liveInvoice->invoice_number ?? '(draft)' }}</a>
            @elseif ($canCreateInvoice)
                <button type="button" wire:click="createInvoice" wire:loading.attr="disabled" class="{{ $secondaryButton }}"><x-heroicon-o-document-text class="size-4" aria-hidden="true" />Create invoice</button>
            @endif
            @if ($canWrite)
                @if ($order->status === OrderStatus::Draft)
                    <a href="{{ route('orders.edit', $order) }}" class="{{ $secondaryButton }}"><x-heroicon-o-pencil-square class="size-4" aria-hidden="true" />Edit</a>
                @endif
                @if (in_array(OrderStatus::Cancelled, $nextStatuses, true))
                    <button type="button" wire:click="cancel" wire:confirm="Cancel {{ $order->order_number }}? This cannot be undone." class="{{ $secondaryButton }} text-danger-ink"><x-heroicon-o-x-circle class="size-4" aria-hidden="true" />Cancel order</button>
                @endif
                @if (in_array(OrderStatus::Confirmed, $nextStatuses, true))
                    <button type="button" wire:click="confirm" wire:loading.attr="disabled" class="{{ $primaryButton }}"><x-heroicon-o-check-circle class="size-4" aria-hidden="true" />Confirm</button>
                @elseif (in_array(OrderStatus::Dispatched, $nextStatuses, true))
                    <button type="button" wire:click="dispatchOrder" wire:confirm="Dispatch {{ $order->order_number }}? Stock will be deducted oldest batch first." wire:loading.attr="disabled" class="{{ $primaryButton }}"><x-heroicon-o-truck class="size-4" aria-hidden="true" />Dispatch</button>
                @elseif (in_array(OrderStatus::Delivered, $nextStatuses, true))
                    <button type="button" wire:click="deliver" wire:loading.attr="disabled" class="{{ $primaryButton }}"><x-heroicon-o-check-badge class="size-4" aria-hidden="true" />Mark delivered</button>
                @endif
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($problems !== [])
        <div role="alert" class="rounded-md border border-danger/35 bg-danger-soft px-4 py-3 text-sm text-danger-ink">
            <p class="flex items-center gap-2 font-semibold"><x-heroicon-o-exclamation-triangle class="size-5 shrink-0" aria-hidden="true" />Nothing was changed.</p>
            <ul class="mt-1.5 list-disc pl-11 text-[13px]">
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid items-start gap-[18px] lg:grid-cols-[1.7fr_1fr]">
        <div class="flex min-w-0 flex-col gap-[18px]">
            <section aria-labelledby="order-lines-heading" class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
                <h2 id="order-lines-heading" class="border-b border-border px-[18px] py-3.5 text-sm font-bold text-fg">Lines</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] border-collapse text-left">
                        <caption class="sr-only">Order lines</caption>
                        <thead class="bg-surface-muted">
                            <tr>
                                <th scope="col" class="{{ $thClasses }}">Product</th>
                                <th scope="col" class="{{ $thClasses }} text-right">Quantity</th>
                                <th scope="col" class="{{ $thClasses }} text-right">Unit price</th>
                                <th scope="col" class="{{ $thClasses }} text-right">Line total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($order->items as $item)
                                <tr wire:key="detail-item-{{ $item->id }}">
                                    <td class="border-b border-border px-[18px] py-3 text-[13px] text-fg"><span class="rounded bg-tag-soft px-2.5 py-[3px] text-[11px] font-bold text-tag-ink">{{ $item->product->unit_type }}</span> <span class="ml-1">{{ $item->product->name }}</span></td>
                                    <td class="border-b border-border px-[18px] py-3 text-right text-[13px] tabular-nums text-fg-soft">{{ number_format($item->quantity) }}</td>
                                    <td class="border-b border-border px-[18px] py-3 text-right text-[13px] tabular-nums text-fg-soft">{{ $item->effectiveUnitPrice() === null ? 'No price set' : Money::format($item->effectiveUnitPrice()) }}</td>
                                    <td class="border-b border-border px-[18px] py-3 text-right text-[13px] font-semibold tabular-nums text-fg">{{ Money::format($item->effectiveLineTotal()) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row" colspan="3" class="px-[18px] py-3.5 text-right text-[13px] font-semibold text-fg-soft">Total{{ $order->status === OrderStatus::Draft ? ' at current prices' : '' }}</th>
                                <td class="px-[18px] py-3.5 text-right text-base font-extrabold tabular-nums text-fg">{{ Money::format($order->total()) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @if ($order->notes)
                    <p class="border-t border-border px-[18px] py-3 text-[13px] text-fg-soft"><span class="font-semibold text-fg">Notes:</span> {{ $order->notes }}</p>
                @endif
            </section>

            @if (in_array($order->status, [OrderStatus::Dispatched, OrderStatus::Delivered], true))
                <section aria-labelledby="order-allocation-heading" class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
                    <div class="border-b border-border px-[18px] py-3.5">
                        <h2 id="order-allocation-heading" class="text-sm font-bold text-fg">Stock allocation</h2>
                        <p class="mt-0.5 text-xs text-muted">Units taken oldest batch first when the order was dispatched.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[520px] border-collapse text-left">
                            <caption class="sr-only">Batches and locations each dispatched unit came from</caption>
                            <thead class="bg-surface-muted">
                                <tr>
                                    <th scope="col" class="{{ $thClasses }}">Product</th>
                                    <th scope="col" class="{{ $thClasses }}">Batch</th>
                                    <th scope="col" class="{{ $thClasses }}">Location</th>
                                    <th scope="col" class="{{ $thClasses }} text-right">Units</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($allocations as $allocation)
                                    <tr wire:key="allocation-{{ $allocation->id }}">
                                        <td class="border-b border-border px-[18px] py-3 text-[13px] font-semibold text-fg">{{ $allocation->product?->unit_type }}</td>
                                        <td class="border-b border-border px-[18px] py-3 font-mono text-xs text-fg-soft">B-{{ str_pad((string) $allocation->stock?->batch_id, 5, '0', STR_PAD_LEFT) }} <span class="ml-1 font-sans text-muted">{{ $settingsDate->formatDate($allocation->stock?->batch?->batch_date) }}</span></td>
                                        <td class="border-b border-border px-[18px] py-3 text-[13px] text-fg-soft">{{ $allocation->stock?->warehouse_location ?: '—' }}</td>
                                        <td class="border-b border-border px-[18px] py-3 text-right text-[13px] tabular-nums text-fg">{{ number_format(abs($allocation->quantity_change)) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-[18px] py-6 text-center text-sm text-muted">No stock movements were found for this order.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        <section aria-labelledby="order-timeline-heading" class="rounded-lg border border-border bg-surface p-[18px] shadow-sm">
            <h2 id="order-timeline-heading" class="text-sm font-bold text-fg">Status timeline</h2>
            <ol class="mt-4 space-y-4">
                @foreach ($timeline as $entry)
                    @php
                        $statusKey = $entry->action === 'orders.status_changed' ? ($entry->new_values['status'] ?? '') : $entry->action;
                        $entryStatus = OrderStatus::tryFrom((string) ($entry->new_values['status'] ?? 'draft')) ?? OrderStatus::Draft;
                    @endphp
                    <li wire:key="timeline-{{ $entry->id }}" class="flex gap-3">
                        <span aria-hidden="true" class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $entryStatus->dotClasses() }}"></span>
                        <div>
                            <p class="text-[13px] font-semibold text-fg">{{ $timelineLabels[$statusKey] ?? $statusKey }}</p>
                            <p class="text-xs text-muted">{{ $settingsDate->formatDateTime($entry->timestamp) }} · {{ $entry->user?->name ?? 'System' }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
            <dl class="mt-5 grid grid-cols-2 gap-3 border-t border-border pt-4 text-xs">
                <div><dt class="font-bold uppercase tracking-wide text-muted">Customer</dt><dd class="mt-0.5 text-[13px] text-fg">{{ $order->customer->name }}</dd></div>
                <div><dt class="font-bold uppercase tracking-wide text-muted">Payment terms</dt><dd class="mt-0.5 text-[13px] text-fg">{{ \App\Support\Plural::count($order->customer->payment_terms_days, 'day') }}</dd></div>
                <div><dt class="font-bold uppercase tracking-wide text-muted">Contact</dt><dd class="mt-0.5 text-[13px] text-fg">{{ $order->customer->contact_number ?? '—' }}</dd></div>
                <div><dt class="font-bold uppercase tracking-wide text-muted">Created by</dt><dd class="mt-0.5 text-[13px] text-fg">{{ $order->creator?->name ?? '—' }}</dd></div>
            </dl>
        </section>
    </div>
</div>
