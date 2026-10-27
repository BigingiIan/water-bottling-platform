@php
    $inputClasses = 'w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-[13px] text-gray-800';
    $thClasses = 'border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500';
@endphp

<div class="flex flex-col gap-6">
    <x-page-header help="orders"
        :title="$order ? 'Edit '.$order->order_number : 'New order'"
        subtitle="Draft orders can be edited freely. Confirming reserves stock and fixes the prices."
    >
        <x-slot:actions>
            <a href="{{ $order ? route('orders.show', $order) : route('orders.index') }}" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">
                <x-heroicon-o-arrow-left class="size-4" aria-hidden="true" />
                Back
            </a>
        </x-slot:actions>
    </x-page-header>

    @error('confirm')
        <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <p class="flex items-center gap-2 font-semibold"><x-heroicon-o-exclamation-triangle class="size-5 shrink-0" aria-hidden="true" />{{ $message }}</p>
            <ul class="mt-1.5 list-disc pl-11 text-[13px]">
                @foreach ($shortages as $shortage)
                    <li>{{ $shortage }}</li>
                @endforeach
            </ul>
        </div>
    @enderror

    <form wire:submit="saveDraft" class="flex flex-col gap-6">
        <section aria-labelledby="order-customer-heading" class="rounded-lg border border-gray-200 bg-white p-[18px] shadow-sm">
            <h2 id="order-customer-heading" class="text-sm font-bold text-gray-900">Customer and delivery</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div class="md:col-span-1">
                    <label for="order-customer" class="mb-1.5 block text-xs font-semibold text-gray-600">Customer</label>
                    <div class="flex gap-2">
                        <select id="order-customer" wire:model="customerId" class="{{ $inputClasses }}">
                            <option value="">Select customer…</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="openCustomerModal" class="inline-flex shrink-0 items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                            <x-heroicon-o-plus class="size-4" aria-hidden="true" />New
                        </button>
                    </div>
                    @error('customerId') <p role="alert" class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="order-requested-date" class="mb-1.5 block text-xs font-semibold text-gray-600">Requested delivery date</label>
                    <input id="order-requested-date" type="date" wire:model="requestedDate" class="{{ $inputClasses }}">
                    @error('requestedDate') <p role="alert" class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="order-notes" class="mb-1.5 block text-xs font-semibold text-gray-600">Notes</label>
                    <input id="order-notes" type="text" wire:model="notes" class="{{ $inputClasses }}">
                    @error('notes') <p role="alert" class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section aria-labelledby="order-lines-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-[18px] py-3.5">
                <h2 id="order-lines-heading" class="text-sm font-bold text-gray-900">Lines</h2>
                <button type="button" wire:click="addLine" class="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 hover:text-blue-900">
                    <x-heroicon-o-plus class="size-4" aria-hidden="true" />Add line
                </button>
            </div>
            @error('lines') <p role="alert" class="border-b border-red-100 bg-red-50 px-[18px] py-2 text-xs text-red-700">{{ $message }}</p> @enderror
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] border-collapse text-left">
                    <caption class="sr-only">Order lines with product, quantity, unit price, and line total.</caption>
                    <thead class="bg-gray-50">
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
                            <tr wire:key="order-line-{{ $index }}" @class(['align-top', 'bg-red-50/50' => $shortage])>
                                <td class="border-b border-gray-100 px-[18px] py-3">
                                    <label class="sr-only" for="line-{{ $index }}-product">Product for line {{ $index + 1 }}</label>
                                    <select id="line-{{ $index }}-product" wire:model.live="lines.{{ $index }}.product_id" class="{{ $inputClasses }}">
                                        <option value="">Select product…</option>
                                        @foreach ($products as $productOption)
                                            <option value="{{ $productOption->id }}">{{ $productOption->unit_type }} · {{ $productOption->name }}</option>
                                        @endforeach
                                    </select>
                                    @if ($stock)
                                        <p class="mt-1 text-[11px] text-gray-500">{{ \App\Support\Plural::count($stock['available'], 'unit') }} available{{ $stock['reserved'] > 0 ? ' · '.number_format($stock['reserved']).' reserved by other orders' : '' }}</p>
                                    @endif
                                    @error("lines.{$index}.product_id") <p role="alert" class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
                                    @if ($shortage) <p role="alert" class="mt-1 text-[11px] font-semibold text-red-700">{{ $shortage }}</p> @endif
                                </td>
                                <td class="border-b border-gray-100 px-[18px] py-3">
                                    <label class="sr-only" for="line-{{ $index }}-quantity">Quantity for line {{ $index + 1 }}</label>
                                    <input id="line-{{ $index }}-quantity" type="number" min="1" step="1" wire:model.live.debounce.300ms="lines.{{ $index }}.quantity" class="{{ $inputClasses }} tabular-nums">
                                    @error("lines.{$index}.quantity") <p role="alert" class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
                                </td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-right text-[13px] tabular-nums text-gray-700">
                                    @if (! $product)
                                        —
                                    @elseif ($product->unit_price_kes === null)
                                        <span class="text-amber-700">No price set</span>
                                    @else
                                        {{ \App\Services\Orders\Money::format((string) $product->unit_price_kes) }}
                                    @endif
                                </td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-right text-[13px] font-semibold tabular-nums text-gray-900">{{ $lineTotals[$index] === null ? '—' : \App\Services\Orders\Money::format($lineTotals[$index]) }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-right">
                                    <button type="button" wire:click="removeLine({{ $index }})" class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600" aria-label="Remove line {{ $index + 1 }}">
                                        <x-heroicon-o-trash class="size-5" aria-hidden="true" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row" colspan="3" class="px-[18px] py-3.5 text-right text-[13px] font-semibold text-gray-600">Order total</th>
                            <td class="px-[18px] py-3.5 text-right text-base font-extrabold tabular-nums text-gray-900">{{ \App\Services\Orders\Money::format($orderTotal) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="border-t border-gray-100 px-[18px] py-2.5 text-[11.5px] text-gray-500">Prices shown are current product prices; they are fixed on the order when it is confirmed.</p>
        </section>

        <div class="flex flex-wrap justify-end gap-2">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60">Save draft</button>
            <button type="button" wire:click="confirmOrder" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
                <x-heroicon-o-check-circle class="size-4" aria-hidden="true" />
                Confirm order
            </button>
        </div>
    </form>

    @if ($customerModalOpen)
        <x-modal-dialog id="quick-customer" title="Add customer" close="closeCustomerModal">
            @include('livewire.partials.customer-fields', ['inputClasses' => $inputClasses])
            <x-slot:footer>
                <button type="button" wire:click="closeCustomerModal" class="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700">Cancel</button>
                <button type="button" wire:click="saveCustomer" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700">Add and select</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif
</div>
