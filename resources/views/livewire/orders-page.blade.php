@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $thClasses = 'border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500';
    $inputClasses = 'w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-[13px] text-gray-800';
    $filterClasses = 'rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700';
@endphp

<div class="flex flex-col gap-6" x-data="{ toast: false, toastMessage: '' }" x-on:orders-toast.window="toastMessage = $event.detail.message; toast = true; setTimeout(() => toast = false, 4000)">
    <div x-show="toast" x-transition role="status" style="display: none" class="fixed right-4 top-4 z-40 rounded-md border border-green-200 bg-green-50 px-4 py-2.5 text-sm font-medium text-green-800 shadow-lg">
        <span x-text="toastMessage"></span>
    </div>

    <x-page-header title="Orders" subtitle="Customer orders, dispatch, and delivery.">
        @if ($canWrite)
            <x-slot:actions>
                @if ($activeTab === 'customers')
                    <button type="button" wire:click="openCustomerModal" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700">
                        <x-heroicon-o-plus class="size-4" aria-hidden="true" />
                        Add customer
                    </button>
                @else
                    <a href="{{ route('orders.create') }}" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700">
                        <x-heroicon-o-plus class="size-4" aria-hidden="true" />
                        New order
                    </a>
                @endif
            </x-slot:actions>
        @endif
    </x-page-header>

    <nav aria-label="Orders sections" class="flex flex-wrap gap-1 border-b border-gray-200">
        @foreach (['orders' => 'Orders', 'customers' => 'Customers'] as $tab => $label)
            <button type="button" wire:click="$set('activeTab', '{{ $tab }}')" @class(['border-b-2 px-3 py-2.5 text-[13px] font-semibold', 'border-blue-600 text-blue-700' => $activeTab === $tab, 'border-transparent text-gray-500 hover:text-gray-800' => $activeTab !== $tab]) @if ($activeTab === $tab) aria-current="page" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($activeTab === 'orders')
        <dl class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['label' => 'Open orders', 'value' => number_format($kpis['open']), 'hint' => 'Draft or confirmed', 'icon' => 'clipboard-document-list'],
                ['label' => 'To dispatch today', 'value' => number_format($kpis['toDispatchToday']), 'hint' => 'Confirmed, due today or earlier', 'icon' => 'truck'],
                ['label' => 'Dispatched today', 'value' => number_format($kpis['dispatchedToday']), 'hint' => $settingsDate->formatDate(today()->toDateString()), 'icon' => 'check-circle'],
                ['label' => 'Value this month', 'value' => \App\Services\Orders\Money::format($kpis['valueThisMonth']), 'hint' => 'Dispatched and delivered', 'icon' => 'banknotes'],
            ] as $kpi)
                <div class="rounded-lg border border-gray-200 bg-white px-[18px] py-4">
                    <dt class="flex items-center justify-between text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">
                        {{ $kpi['label'] }}
                        <x-dynamic-component :component="'heroicon-o-'.$kpi['icon']" class="size-5 text-gray-400" aria-hidden="true" />
                    </dt>
                    <dd class="mt-2 text-2xl font-extrabold tabular-nums text-gray-900">{{ $kpi['value'] }}</dd>
                    <dd class="mt-1 text-[11.5px] text-gray-500">{{ $kpi['hint'] }}</dd>
                </div>
            @endforeach
        </dl>

        <section aria-labelledby="orders-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <h2 id="orders-heading" class="sr-only">Orders</h2>
            <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 bg-gray-50 px-[18px] py-3">
                <label class="sr-only" for="orders-status-filter">Filter by status</label>
                <select id="orders-status-filter" wire:model.live="statusFilter" class="{{ $filterClasses }}">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
                <label class="sr-only" for="orders-customer-filter">Filter by customer</label>
                <select id="orders-customer-filter" wire:model.live="customerFilter" class="{{ $filterClasses }} max-w-56">
                    <option value="">All customers</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-1.5 text-xs text-gray-500">From <input type="date" wire:model.live="dateFrom" aria-label="Order date from" class="{{ $filterClasses }}"></label>
                <label class="flex items-center gap-1.5 text-xs text-gray-500">To <input type="date" wire:model.live="dateTo" aria-label="Order date to" class="{{ $filterClasses }}"></label>
                <span class="ml-auto text-xs text-gray-500">{{ \App\Support\Plural::count($orders->total(), 'order') }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] border-collapse text-left">
                    <caption class="sr-only">Orders with customer, date, item count, total, and status.</caption>
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="{{ $thClasses }}">Order</th>
                            <th scope="col" class="{{ $thClasses }}">Customer</th>
                            <th scope="col" class="{{ $thClasses }}">Order date</th>
                            <th scope="col" class="{{ $thClasses }} text-right">Items</th>
                            <th scope="col" class="{{ $thClasses }} text-right">Total</th>
                            <th scope="col" class="{{ $thClasses }}">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $order)
                            <tr wire:key="order-{{ $order->id }}" class="hover:bg-gray-50">
                                <td class="border-b border-gray-100 px-[18px] py-3"><a href="{{ route('orders.show', $order) }}" class="font-mono text-xs font-semibold text-blue-700 hover:text-blue-900 hover:underline">{{ $order->order_number }}</a></td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-800">{{ $order->customer->name }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-600">{{ $settingsDate->formatDate($order->order_date) }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-right text-[13px] tabular-nums text-gray-700">{{ $order->items_count }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-right text-[13px] tabular-nums text-gray-900">{{ \App\Services\Orders\Money::format($order->total()) }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3"><x-order-status-badge :status="$order->status" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-[18px] py-10 text-center text-sm text-gray-500">
                                    No orders match these filters.
                                    @if ($canWrite)
                                        <a href="{{ route('orders.create') }}" class="ml-1 font-semibold text-blue-700 hover:text-blue-900">Create an order</a>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($orders->hasPages())
                <div class="border-t border-gray-100 px-[18px] py-3">{{ $orders->links() }}</div>
            @endif
        </section>
    @else
        <section aria-labelledby="customers-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <h2 id="customers-heading" class="sr-only">Customers</h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] border-collapse text-left">
                    <caption class="sr-only">Customers with contact details, type, payment terms, and order count.</caption>
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="{{ $thClasses }}">Customer</th>
                            <th scope="col" class="{{ $thClasses }}">Contact</th>
                            <th scope="col" class="{{ $thClasses }}">Address</th>
                            <th scope="col" class="{{ $thClasses }}">Type</th>
                            <th scope="col" class="{{ $thClasses }} text-right">Payment terms</th>
                            <th scope="col" class="{{ $thClasses }} text-right">Orders</th>
                            <th scope="col" class="{{ $thClasses }}"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customerRows as $customer)
                            <tr wire:key="customer-{{ $customer->id }}" class="hover:bg-gray-50">
                                <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] font-semibold text-gray-900">{{ $customer->name }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700">{{ $customer->contact_number ?? '—' }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700">{{ $customer->address ?? '—' }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700">{{ $customer->customer_type ? ucfirst($customer->customer_type) : '—' }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-right text-[13px] tabular-nums text-gray-700">{{ \App\Support\Plural::count($customer->payment_terms_days, 'day') }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-right text-[13px] tabular-nums text-gray-700">{{ $customer->orders_count }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-right">
                                    @if ($canWrite)
                                        <button type="button" wire:click="openCustomerModal({{ $customer->id }})" class="text-xs font-semibold text-blue-700 hover:text-blue-900">Edit<span class="sr-only"> {{ $customer->name }}</span></button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-[18px] py-10 text-center text-sm text-gray-500">No customers yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($customerRows->hasPages())
                <div class="border-t border-gray-100 px-[18px] py-3">{{ $customerRows->links() }}</div>
            @endif
        </section>
    @endif

    @if ($customerModalOpen)
        <x-modal-dialog id="customer-modal" :title="$editingCustomerId ? 'Edit customer' : 'Add customer'" close="closeCustomerModal">
            @include('livewire.partials.customer-fields', ['inputClasses' => $inputClasses])
            <x-slot:footer>
                <button type="button" wire:click="closeCustomerModal" class="rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700">Cancel</button>
                <button type="button" wire:click="saveCustomer" wire:loading.attr="disabled" class="rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:opacity-60">Save customer</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif
</div>
