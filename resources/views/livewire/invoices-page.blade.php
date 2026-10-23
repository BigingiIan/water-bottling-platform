@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $money = fn ($value): string => \App\Services\Orders\Money::format((string) $value);
    $thClasses = 'border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500';
    $filterClasses = 'rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700';
@endphp

<div class="flex flex-col gap-6">
    <x-page-header title="Invoices" subtitle="Internal invoices raised from dispatched orders, and the payments against them." />

    <dl class="grid gap-3.5 sm:grid-cols-3">
        @foreach ([
            ['label' => 'Outstanding', 'value' => $money($kpis['outstanding']), 'hint' => 'Issued and partially paid', 'icon' => 'banknotes'],
            ['label' => 'Overdue', 'value' => $money($kpis['overdueAmount']), 'hint' => \App\Support\Plural::count($kpis['overdueCount'], 'invoice').' past due', 'icon' => 'exclamation-triangle'],
            ['label' => 'Paid this month', 'value' => $money($kpis['paidThisMonth']), 'hint' => 'Payments received, excluding reversals', 'icon' => 'check-circle'],
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

    <section aria-labelledby="invoices-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
        <h2 id="invoices-heading" class="sr-only">Invoices</h2>
        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 bg-gray-50 px-[18px] py-3">
            <label class="sr-only" for="invoice-status-filter">Filter by status</label>
            <select id="invoice-status-filter" wire:model.live="statusFilter" class="{{ $filterClasses }}">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="invoice-customer-filter">Filter by customer</label>
            <select id="invoice-customer-filter" wire:model.live="customerFilter" class="{{ $filterClasses }} max-w-56">
                <option value="">All customers</option>
                @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-1.5 text-xs text-gray-500">Issued from <input type="date" wire:model.live="dateFrom" aria-label="Issued from" class="{{ $filterClasses }}"></label>
            <label class="flex items-center gap-1.5 text-xs text-gray-500">to <input type="date" wire:model.live="dateTo" aria-label="Issued to" class="{{ $filterClasses }}"></label>
            <label class="inline-flex items-center gap-2 text-xs font-medium text-gray-700">
                <input type="checkbox" wire:model.live="overdueOnly" class="size-4 rounded border-gray-300 text-red-600 focus:ring-red-500">
                Overdue only
            </label>
            <span class="ml-auto text-xs text-gray-500">{{ \App\Support\Plural::count($invoices->total(), 'invoice') }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[860px] border-collapse text-left">
                <caption class="sr-only">Invoices with customer, dates, total, balance, and status.</caption>
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="{{ $thClasses }}">Invoice</th>
                        <th scope="col" class="{{ $thClasses }}">Customer</th>
                        <th scope="col" class="{{ $thClasses }}">Issued</th>
                        <th scope="col" class="{{ $thClasses }}">Due</th>
                        <th scope="col" class="{{ $thClasses }} text-right">Total</th>
                        <th scope="col" class="{{ $thClasses }} text-right">Balance</th>
                        <th scope="col" class="{{ $thClasses }}">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr wire:key="invoice-{{ $invoice->id }}" class="hover:bg-gray-50">
                            <td class="border-b border-gray-100 px-[18px] py-3"><a href="{{ route('invoices.show', $invoice) }}" class="font-mono text-xs font-semibold text-blue-700 hover:text-blue-900 hover:underline">{{ $invoice->displayNumber() }}</a></td>
                            <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-800">{{ $invoice->customer->name }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-600">{{ $invoice->issue_date ? $settingsDate->formatDate($invoice->issue_date) : '—' }}</td>
                            <td @class(['border-b border-gray-100 px-[18px] py-3 text-[13px]', 'font-semibold text-red-700' => $invoice->isOverdue(), 'text-gray-600' => ! $invoice->isOverdue()])>{{ $invoice->due_date ? $settingsDate->formatDate($invoice->due_date) : '—' }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 text-right text-[13px] tabular-nums text-gray-900">{{ $money($invoice->total) }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 text-right text-[13px] tabular-nums text-gray-900">{{ $invoice->status === \App\Services\Invoicing\InvoiceStatus::Void ? '—' : $money($invoice->balance()) }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3"><x-invoice-status-badge :invoice="$invoice" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-[18px] py-10 text-center text-sm text-gray-500">No invoices match these filters. Invoices are created from dispatched orders.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($invoices->hasPages())
            <div class="border-t border-gray-100 px-[18px] py-3">{{ $invoices->links() }}</div>
        @endif
    </section>
</div>
