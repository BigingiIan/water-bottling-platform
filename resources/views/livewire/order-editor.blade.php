@php
    $inputClasses = 'w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 text-[13px] text-fg';
    $thClasses = 'border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted';
@endphp

<div class="flex flex-col gap-6">
    <x-page-header help="orders"
        :title="$order ? 'Edit '.$order->order_number : 'New order'"
        subtitle="Draft orders can be edited freely. Confirming reserves stock and fixes the prices."
    >
        <x-slot:actions>
            <a href="{{ $order ? route('orders.show', $order) : route('orders.index') }}" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">
                <x-heroicon-o-arrow-left class="size-4" aria-hidden="true" />
                Back
            </a>
        </x-slot:actions>
    </x-page-header>

    @error('confirm')
        <div role="alert" class="rounded-md border border-danger/35 bg-danger-soft px-4 py-3 text-sm text-danger-ink">
            <p class="flex items-center gap-2 font-semibold"><x-heroicon-o-exclamation-triangle class="size-5 shrink-0" aria-hidden="true" />{{ $message }}</p>
            <ul class="mt-1.5 list-disc pl-11 text-[13px]">
                @foreach ($shortages as $shortage)
                    <li>{{ $shortage }}</li>
                @endforeach
            </ul>
        </div>
    @enderror

    <form wire:submit="saveDraft" class="flex flex-col gap-6">
        <section aria-labelledby="order-customer-heading" class="rounded-lg border border-border bg-surface p-[18px] shadow-sm">
            <h2 id="order-customer-heading" class="text-sm font-bold text-fg">Customer and delivery</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div class="md:col-span-1">
                    <label for="order-customer" class="mb-1.5 block text-xs font-semibold text-fg-soft">Customer</label>
                    <div class="flex gap-2">
                        <select id="order-customer" wire:model="customerId" class="{{ $inputClasses }}">
                            <option value="">Select customer…</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="openCustomerModal" class="inline-flex shrink-0 items-center gap-1 rounded-md border border-border-strong bg-surface px-2.5 text-xs font-semibold text-fg-soft hover:bg-surface-muted">
                            <x-heroicon-o-plus class="size-4" aria-hidden="true" />New
                        </button>
                    </div>
                    @error('customerId') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="order-requested-date" class="mb-1.5 block text-xs font-semibold text-fg-soft">Requested delivery date</label>
                    <input id="order-requested-date" type="date" wire:model="requestedDate" class="{{ $inputClasses }}">
                    @error('requestedDate') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="order-notes" class="mb-1.5 block text-xs font-semibold text-fg-soft">Notes</label>
                    <input id="order-notes" type="text" wire:model="notes" class="{{ $inputClasses }}">
                    @error('notes') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section aria-labelledby="order-lines-heading" class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-border px-[18px] py-3.5">
                <h2 id="order-lines-heading" class="text-sm font-bold text-fg">Lines</h2>
                <button type="button" wire:click="addLine" class="inline-flex items-center gap-1 text-xs font-semibold text-accent-ink hover:text-accent-ink">
                    <x-heroicon-o-plus class="size-4" aria-hidden="true" />Add line
                </button>
            </div>
            @error('lines') <p role="alert" class="border-b border-danger/20 bg-danger-soft px-[18px] py-2 text-xs text-danger-ink">{{ $message }}</p> @enderror
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] border-collapse text-left">
                    <caption class="sr-only">Order lines with product, quantity, unit price, and line total.</caption>
                    <thead class="bg-surface-muted">
                        <tr>
                            <th scope="col" class="{{ $thClasses }}">Product</th>
                            <th scope="col" class="{{ $thClasses }} w-36">Quantity</th>
                            <th scope="col" class="{{ $thClasses }} text-right">Unit price</th>
                            <th scope="col" class="{{ $thClasses }} text-right">Line total</th>
                            <th scope="col" class="{{ $thClasses }}"><span class="sr-only">Remove</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $index => $line)
                            @php
                                $product = $productsById->get((int) $line['product_id']);
                                $stock = $product ? $availability[$product->id] : null;
                                $shortage = $product ? ($shortages[$product->id] ?? null) : null;
                            @endphp
                            <tr wire:key="order-line-{{ $index }}" @class(['align-top', 'bg-danger-soft/50' => $shortage])>
                                <td class="border-b border-border px-[18px] py-3">
                                    <label class="sr-only" for="line-{{ $index }}-product">Product for line {{ $index + 1 }}</label>
                                    <select id="line-{{ $index }}-product" wire:model.live="lines.{{ $index }}.product_id" class="{{ $inputClasses }}">
                                        <option value="">Select product…</option>
                                        @foreach ($products as $productOption)
                                            <option value="{{ $productOption->id }}">{{ $productOption->unit_type }} · {{ $productOption->name }}</option>
                                        @endforeach
                                    </select>
                                    @if ($stock)
                                        <p class="mt-1 text-[11px] text-muted">{{ \App\Support\Plural::count($stock['available'], 'unit') }} available{{ $stock['reserved'] > 0 ? ' · '.number_format($stock['reserved']).' reserved by other orders' : '' }}</p>
                                    @endif
                                    @error("lines.{$index}.product_id") <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                                    @if ($shortage) <p role="alert" class="mt-1 text-[11px] font-semibold text-danger-ink">{{ $shortage }}</p> @endif
                                </td>
                                <td class="border-b border-border px-[18px] py-3">
                                    <label class="sr-only" for="line-{{ $index }}-quantity">Quantity for line {{ $index + 1 }}</label>
                                    <input id="line-{{ $index }}-quantity" type="number" min="1" step="1" wire:model.live.debounce.300ms="lines.{{ $index }}.quantity" class="{{ $inputClasses }} tabular-nums">
                                    @error("lines.{$index}.quantity") <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                                </td>
                                <td class="border-b border-border px-[18px] py-3 text-right text-[13px] tabular-nums text-fg-soft">
                                    @if (! $product)
                                        —
                                    @elseif ($product->unit_price_kes === null)
                                        <span class="text-warning-ink">No price set</span>
                                    @else
                                        {{ \App\Services\Orders\Money::format((string) $product->unit_price_kes) }}
                                    @endif
                                </td>
                                <td class="border-b border-border px-[18px] py-3 text-right text-[13px] font-semibold tabular-nums text-fg">{{ $lineTotals[$index] === null ? '—' : \App\Services\Orders\Money::format($lineTotals[$index]) }}</td>
                                <td class="border-b border-border px-[18px] py-3 text-right">
                                    <button type="button" wire:click="removeLine({{ $index }})" class="rounded-md p-1 text-muted hover:bg-surface-strong hover:text-danger-ink" aria-label="Remove line {{ $index + 1 }}">
                                        <x-heroicon-o-trash class="size-5" aria-hidden="true" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row" colspan="3" class="px-[18px] py-3.5 text-right text-[13px] font-semibold text-fg-soft">Order total</th>
                            <td class="px-[18px] py-3.5 text-right text-base font-extrabold tabular-nums text-fg">{{ \App\Services\Orders\Money::format($orderTotal) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="border-t border-border px-[18px] py-2.5 text-[11.5px] text-muted">Prices shown are current product prices; they are fixed on the order when it is confirmed.</p>
        </section>

        <div class="flex flex-wrap justify-end gap-2">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted disabled:opacity-60">Save draft</button>
            <button type="button" wire:click="confirmOrder" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover disabled:opacity-60">
                <x-heroicon-o-check-circle class="size-4" aria-hidden="true" />
                Confirm order
            </button>
        </div>
    </form>

    @if ($customerModalOpen)
        <x-modal-dialog id="quick-customer" title="Add customer" close="closeCustomerModal">
            @include('livewire.partials.customer-fields', ['inputClasses' => $inputClasses])
            <x-slot:footer>
                <button type="button" wire:click="closeCustomerModal" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft">Cancel</button>
                <button type="button" wire:click="saveCustomer" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">Add and select</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif
</div>
