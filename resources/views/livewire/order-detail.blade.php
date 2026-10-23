@use('App\Services\Orders\Money')
@use('App\Services\Orders\OrderStatus')

@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $thClasses = 'border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500';
    $secondaryButton = 'inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60';
    $primaryButton = 'inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:opacity-60';
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
    <div x-show="toast" x-transition role="status" style="display: none" class="fixed right-4 top-4 z-40 rounded-md border border-green-200 bg-green-50 px-4 py-2.5 text-sm font-medium text-green-800 shadow-lg">
        <span x-text="toastMessage"></span>
    </div>

    <x-page-header :title="$order->order_number" :subtitle="$order->customer->name.' · ordered '.$settingsDate->formatDate($order->order_date).($order->requested_date ? ' · requested for '.$settingsDate->formatDate($order->requested_date) : '')">
        <x-slot:actions>
            <x-order-status-badge :status="$order->status" class="mr-1" />
            <a href="{{ route('orders.index') }}" class="{{ $secondaryButton }}"><x-heroicon-o-arrow-left class="size-4" aria-hidden="true" />All orders</a>
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
                    <button type="button" wire:click="cancel" wire:confirm="Cancel {{ $order->order_number }}? This cannot be undone." class="{{ $secondaryButton }} text-red-700"><x-heroicon-o-x-circle class="size-4" aria-hidden="true" />Cancel order</button>
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
        <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
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
            <section aria-labelledby="order-lines-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <h2 id="order-lines-heading" class="border-b border-gray-100 px-[18px] py-3.5 text-sm font-bold text-gray-900">Lines</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] border-collapse text-left">
                        <caption class="sr-only">Order lines</caption>
                        <thead class="bg-gray-50">
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
                                    <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-800"><span class="rounded bg-teal-50 px-2.5 py-[3px] text-[11px] font-bold text-[#0f766e]">{{ $item->product->unit_type }}</span> <span class="ml-1">{{ $item->product->name }}</span></td>
                                    <td class="border-b border-gray-100 px-[18px] py-3 text-right text-[13px] tabular-nums text-gray-700">{{ number_format($item->quantity) }}</td>
                                    <td class="border-b border-gray-100 px-[18px] py-3 text-right text-[13px] tabular-nums text-gray-700">{{ $item->effectiveUnitPrice() === null ? 'No price set' : Money::format($item->effectiveUnitPrice()) }}</td>
                                    <td class="border-b border-gray-100 px-[18px] py-3 text-right text-[13px] font-semibold tabular-nums text-gray-900">{{ Money::format($item->effectiveLineTotal()) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row" colspan="3" class="px-[18px] py-3.5 text-right text-[13px] font-semibold text-gray-600">Total{{ $order->status === OrderStatus::Draft ? ' at current prices' : '' }}</th>
                                <td class="px-[18px] py-3.5 text-right text-base font-extrabold tabular-nums text-gray-900">{{ Money::format($order->total()) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @if ($order->notes)
                    <p class="border-t border-gray-100 px-[18px] py-3 text-[13px] text-gray-600"><span class="font-semibold text-gray-800">Notes:</span> {{ $order->notes }}</p>
                @endif
            </section>

            @if (in_array($order->status, [OrderStatus::Dispatched, OrderStatus::Delivered], true))
                <section aria-labelledby="order-allocation-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-[18px] py-3.5">
                        <h2 id="order-allocation-heading" class="text-sm font-bold text-gray-900">Stock allocation</h2>
                        <p class="mt-0.5 text-xs text-gray-500">Units taken oldest batch first when the order was dispatched.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[520px] border-collapse text-left">
                            <caption class="sr-only">Batches and locations each dispatched unit came from</caption>
                            <thead class="bg-gray-50">
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
                                        <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] font-semibold text-gray-800">{{ $allocation->product?->unit_type }}</td>
                                        <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">B-{{ str_pad((string) $allocation->stock?->batch_id, 5, '0', STR_PAD_LEFT) }} <span class="ml-1 font-sans text-gray-500">{{ $settingsDate->formatDate($allocation->stock?->batch?->batch_date) }}</span></td>
                                        <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700">{{ $allocation->stock?->warehouse_location ?: '—' }}</td>
                                        <td class="border-b border-gray-100 px-[18px] py-3 text-right text-[13px] tabular-nums text-gray-900">{{ number_format(abs($allocation->quantity_change)) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-[18px] py-6 text-center text-sm text-gray-500">No stock movements were found for this order.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        <section aria-labelledby="order-timeline-heading" class="rounded-lg border border-gray-200 bg-white p-[18px] shadow-sm">
            <h2 id="order-timeline-heading" class="text-sm font-bold text-gray-900">Status timeline</h2>
            <ol class="mt-4 space-y-4">
                @foreach ($timeline as $entry)
                    @php
                        $statusKey = $entry->action === 'orders.status_changed' ? ($entry->new_values['status'] ?? '') : $entry->action;
                        $entryStatus = OrderStatus::tryFrom((string) ($entry->new_values['status'] ?? 'draft')) ?? OrderStatus::Draft;
                    @endphp
                    <li wire:key="timeline-{{ $entry->id }}" class="flex gap-3">
                        <span aria-hidden="true" class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $entryStatus->dotClasses() }}"></span>
                        <div>
                            <p class="text-[13px] font-semibold text-gray-900">{{ $timelineLabels[$statusKey] ?? $statusKey }}</p>
                            <p class="text-xs text-gray-500">{{ $settingsDate->formatDateTime($entry->timestamp) }} · {{ $entry->user?->name ?? 'System' }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
            <dl class="mt-5 grid grid-cols-2 gap-3 border-t border-gray-100 pt-4 text-xs">
                <div><dt class="font-bold uppercase tracking-wide text-gray-500">Customer</dt><dd class="mt-0.5 text-[13px] text-gray-800">{{ $order->customer->name }}</dd></div>
                <div><dt class="font-bold uppercase tracking-wide text-gray-500">Payment terms</dt><dd class="mt-0.5 text-[13px] text-gray-800">{{ \App\Support\Plural::count($order->customer->payment_terms_days, 'day') }}</dd></div>
                <div><dt class="font-bold uppercase tracking-wide text-gray-500">Contact</dt><dd class="mt-0.5 text-[13px] text-gray-800">{{ $order->customer->contact_number ?? '—' }}</dd></div>
                <div><dt class="font-bold uppercase tracking-wide text-gray-500">Created by</dt><dd class="mt-0.5 text-[13px] text-gray-800">{{ $order->creator?->name ?? '—' }}</dd></div>
            </dl>
        </section>
    </div>
</div>
