@php
    $money = fn ($value): string => \App\Services\Orders\Money::format((string) $value);
    $thClasses = 'border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500';
    $tdClasses = 'border-b border-gray-100 px-[18px] py-2.5 text-[13px]';
    $filterClasses = 'rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700';
    $cardClasses = 'overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm';
    $cardHeaderClasses = 'flex flex-wrap items-baseline justify-between gap-2 border-b border-gray-100 px-[18px] py-3';
    $kpiCards = [
        ['key' => 'invoicedNet', 'label' => 'Invoiced (net)', 'hint' => 'Gross '.$money($kpis['invoicedGross']['current']).' incl. VAT', 'icon' => 'document-text'],
        ['key' => 'collected', 'label' => 'Collected', 'hint' => 'Payments received, excluding reversals', 'icon' => 'banknotes'],
        ['key' => 'outstanding', 'label' => 'Outstanding', 'hint' => 'As at '.$asAtLabel, 'icon' => 'scale'],
        ['key' => 'overdue', 'label' => 'Overdue', 'hint' => 'Past due date, as at '.$asAtLabel, 'icon' => 'exclamation-triangle'],
    ];
@endphp

<div class="flex flex-col gap-6">
    <x-page-header title="Revenue" subtitle="What was invoiced, what was collected and what is still owed. Read only.">
        <x-slot:actions>
            <button type="button" wire:click="exportCsv" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">
                <x-heroicon-o-arrow-down-tray class="size-4" aria-hidden="true" />Export CSV
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-white px-[18px] py-3 shadow-sm">
        <label class="sr-only" for="revenue-period">Period</label>
        <select id="revenue-period" wire:model.live="period" class="{{ $filterClasses }}">
            @foreach (\App\Services\Revenue\RevenuePeriod::PRESETS as $value => $label)
                <option value="{{ $value }}" @selected($resolvedPeriod->preset === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @if ($period === 'custom')
            <label class="flex items-center gap-1.5 text-xs text-gray-500">From <input type="date" wire:model.live="from" aria-label="From" class="{{ $filterClasses }}"></label>
            <label class="flex items-center gap-1.5 text-xs text-gray-500">to <input type="date" wire:model.live="to" aria-label="To" class="{{ $filterClasses }}"></label>
        @endif
        <span class="text-xs text-gray-500">{{ $periodLabel }} <span class="text-gray-400">· compared with {{ $previousLabel }}</span></span>
        @error('to')
            <p class="basis-full text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
        @enderror
    </div>

    <dl class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($kpiCards as $card)
            @php
                $kpi = $kpis[$card['key']];
                $change = $kpi['change'];
                $direction = $change === null ? null : (bccomp($change, '0', 1) > 0 ? 'up' : (bccomp($change, '0', 1) < 0 ? 'down' : 'flat'));
            @endphp
            <div class="rounded-lg border border-gray-200 bg-white px-[18px] py-4">
                <dt class="flex items-center justify-between text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">
                    {{ $card['label'] }}
                    <x-dynamic-component :component="'heroicon-o-'.$card['icon']" class="size-5 text-gray-400" aria-hidden="true" />
                </dt>
                <dd class="mt-2 text-2xl font-extrabold tabular-nums text-gray-900">{{ $money($kpi['current']) }}</dd>
                <dd class="mt-1 flex flex-wrap items-center gap-x-1.5 text-[11.5px] text-gray-500">
                    @if ($direction === null)
                        <span class="font-semibold text-gray-600">No previous figure</span>
                    @else
                        <span class="inline-flex items-center gap-0.5 font-semibold text-gray-700">
                            @if ($direction === 'up')
                                <x-heroicon-m-arrow-up class="size-3.5" aria-hidden="true" />
                            @elseif ($direction === 'down')
                                <x-heroicon-m-arrow-down class="size-3.5" aria-hidden="true" />
                            @endif
                            {{ $direction === 'up' ? '+' : '' }}{{ $change }}%
                        </span>
                    @endif
                    <span>vs {{ $money($kpi['previous']) }}</span>
                </dd>
                <dd class="mt-0.5 text-[11.5px] text-gray-500">{{ $card['hint'] }}</dd>
            </div>
        @endforeach
    </dl>

    <section aria-labelledby="revenue-chart-heading" class="{{ $cardClasses }}">
        <div class="{{ $cardHeaderClasses }}">
            <h2 id="revenue-chart-heading" class="text-sm font-bold text-gray-900">Invoiced vs collected</h2>
            <div class="inline-flex rounded-md border border-gray-300 p-0.5" role="group" aria-label="Group by">
                @foreach (\App\Livewire\RevenuePage::GRANULARITIES as $value => $label)
                    <button type="button" wire:click="$set('granularity', '{{ $value }}')" aria-pressed="{{ $resolvedGranularity === $value ? 'true' : 'false' }}" @class([
                        'rounded px-2.5 py-1 text-xs font-semibold',
                        'bg-navy-950 text-white' => $resolvedGranularity === $value,
                        'text-gray-600 hover:bg-gray-100' => $resolvedGranularity !== $value,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div class="px-[18px] py-4">
            <x-revenue-chart :buckets="$series" :granularity="$resolvedGranularity" />
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-2">
        <section aria-labelledby="revenue-products-heading" class="{{ $cardClasses }}">
            <div class="{{ $cardHeaderClasses }}">
                <h2 id="revenue-products-heading" class="text-sm font-bold text-gray-900">Revenue by product</h2>
                <span class="text-xs text-gray-500">Net of VAT, {{ $periodLabel }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left">
                    <caption class="sr-only">Units invoiced, net revenue and share per product.</caption>
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="{{ $thClasses }}">Product</th>
                            <th scope="col" class="{{ $thClasses }} whitespace-nowrap text-right">Units</th>
                            <th scope="col" class="{{ $thClasses }} whitespace-nowrap text-right">Net revenue</th>
                            <th scope="col" class="{{ $thClasses }} whitespace-nowrap text-right">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products['rows'] as $row)
                            <tr wire:key="product-{{ $row['productId'] ?? 'removed' }}">
                                <td class="{{ $tdClasses }} text-gray-800">{{ $row['name'] }}</td>
                                <td class="{{ $tdClasses }} whitespace-nowrap text-right tabular-nums text-gray-700">{{ number_format($row['units']) }}</td>
                                <td class="{{ $tdClasses }} whitespace-nowrap text-right tabular-nums text-gray-900">{{ $money($row['net']) }}</td>
                                <td class="{{ $tdClasses }} whitespace-nowrap text-right tabular-nums text-gray-700">{{ $row['share'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-[18px] py-6 text-center text-[13px] text-gray-500">No invoices were issued in this period.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($products['rows'] !== [])
                        <tfoot>
                            <tr class="font-semibold">
                                <td class="px-[18px] py-2.5 text-[13px] text-gray-900">Total</td>
                                <td class="px-[18px] py-2.5 text-right text-[13px] tabular-nums text-gray-900">{{ number_format($products['totalUnits']) }}</td>
                                <td class="px-[18px] py-2.5 text-right text-[13px] tabular-nums text-gray-900">{{ $money($products['totalNet']) }}</td>
                                <td class="px-[18px] py-2.5 text-right text-[13px] tabular-nums text-gray-900">100.0%</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </section>

        <section aria-labelledby="revenue-customers-heading" class="{{ $cardClasses }}">
            <div class="{{ $cardHeaderClasses }}">
                <h2 id="revenue-customers-heading" class="text-sm font-bold text-gray-900">Top customers</h2>
                <span class="text-xs text-gray-500">Top 10 by net invoiced; balance owed today</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left">
                    <caption class="sr-only">Customers invoiced the most in the period, with their outstanding balance.</caption>
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="{{ $thClasses }}">Customer</th>
                            <th scope="col" class="{{ $thClasses }} whitespace-nowrap text-right">Invoices</th>
                            <th scope="col" class="{{ $thClasses }} whitespace-nowrap text-right">Net invoiced</th>
                            <th scope="col" class="{{ $thClasses }} whitespace-nowrap text-right">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customers as $row)
                            <tr wire:key="customer-{{ $row['customerId'] }}">
                                <td class="{{ $tdClasses }} text-gray-800">{{ $row['name'] }}</td>
                                <td class="{{ $tdClasses }} whitespace-nowrap text-right tabular-nums text-gray-700">{{ $row['invoices'] }}</td>
                                <td class="{{ $tdClasses }} whitespace-nowrap text-right tabular-nums text-gray-900">{{ $money($row['net']) }}</td>
                                <td class="{{ $tdClasses }} whitespace-nowrap text-right tabular-nums text-gray-900">{{ $money($row['outstanding']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-[18px] py-6 text-center text-[13px] text-gray-500">No invoices were issued in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section aria-labelledby="revenue-aging-heading" class="{{ $cardClasses }}">
        <div class="{{ $cardHeaderClasses }}">
            <h2 id="revenue-aging-heading" class="text-sm font-bold text-gray-900">Receivables aging</h2>
            <span class="text-xs text-gray-500">Open balances by days since issue, as of today (not filtered by period)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] border-collapse text-left">
                <caption class="sr-only">Outstanding balance per customer in 0–30, 31–60, 61–90 and over 90 day buckets.</caption>
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="{{ $thClasses }}">Customer</th>
                        @foreach (\App\Services\Revenue\RevenueReport::AGING_BUCKETS as $label)
                            <th scope="col" class="{{ $thClasses }} whitespace-nowrap text-right">{{ $label }}</th>
                        @endforeach
                        <th scope="col" class="{{ $thClasses }} whitespace-nowrap text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($aging['rows'] as $row)
                        <tr wire:key="aging-{{ $row['customerId'] }}">
                            <td class="{{ $tdClasses }} text-gray-800">{{ $row['name'] }}</td>
                            @foreach (array_keys(\App\Services\Revenue\RevenueReport::AGING_BUCKETS) as $bucket)
                                <td @class([$tdClasses, 'whitespace-nowrap text-right tabular-nums', 'text-gray-400' => bccomp($row[$bucket], '0', 2) === 0, 'text-gray-900' => bccomp($row[$bucket], '0', 2) !== 0])>{{ bccomp($row[$bucket], '0', 2) === 0 ? '—' : $money($row[$bucket]) }}</td>
                            @endforeach
                            <td class="{{ $tdClasses }} text-right font-semibold tabular-nums text-gray-900">{{ $money($row['total']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-[18px] py-6 text-center text-[13px] text-gray-500">Nothing is outstanding.</td></tr>
                    @endforelse
                </tbody>
                @if ($aging['rows'] !== [])
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="px-[18px] py-2.5 text-[13px] text-gray-900">Total</td>
                            @foreach ([...array_keys(\App\Services\Revenue\RevenueReport::AGING_BUCKETS), 'total'] as $bucket)
                                <td class="px-[18px] py-2.5 text-right text-[13px] tabular-nums text-gray-900">{{ $money($aging['totals'][$bucket]) }}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>

    <section aria-labelledby="revenue-forecast-heading" class="{{ $cardClasses }}">
        <div class="{{ $cardHeaderClasses }}">
            <div>
                <h2 id="revenue-forecast-heading" class="text-sm font-bold text-gray-900">Expected revenue, next 7 days</h2>
                <p class="mt-0.5 text-xs text-gray-500">Estimate from the demand forecast, not booked revenue: forecast units × current list price ({{ $pricesIncludeVat ? 'prices include VAT' : 'prices exclude VAT' }}).</p>
            </div>
            <span class="text-sm font-bold tabular-nums text-gray-900">{{ $forecast['coveredProducts'] > 0 ? $money($forecast['totalRevenue']) : 'No forecast' }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] border-collapse text-left">
                <caption class="sr-only">Forecast units per day and the revenue they imply, per product.</caption>
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="{{ $thClasses }}">Product</th>
                        @foreach ($forecast['days'] as $day)
                            <th scope="col" class="{{ $thClasses }} px-2 text-right">{{ \Illuminate\Support\Str::before($day['label'], ' 20') }}</th>
                        @endforeach
                        <th scope="col" class="{{ $thClasses }} whitespace-nowrap text-right">Price</th>
                        <th scope="col" class="{{ $thClasses }} whitespace-nowrap text-right">Expected</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($forecast['rows'] as $row)
                        <tr wire:key="forecast-{{ $row['productId'] }}">
                            <td class="{{ $tdClasses }} text-gray-800">
                                {{ $row['name'] }}
                                <span class="block text-[11px] text-gray-500">{{ $row['coveredDays'] === 0 ? 'No forecast' : $row['coveredDays'].' of 7 days forecast' }}</span>
                            </td>
                            @foreach ($row['daily'] as $units)
                                <td @class([$tdClasses, 'px-2 whitespace-nowrap text-right tabular-nums', 'text-gray-700' => $units !== null, 'text-[11px] text-gray-400' => $units === null])>{{ $units === null ? 'no forecast' : number_format((float) $units) }}</td>
                            @endforeach
                            <td class="{{ $tdClasses }} whitespace-nowrap text-right tabular-nums text-gray-700">{{ $money($row['unitPrice']) }}</td>
                            <td class="{{ $tdClasses }} text-right font-semibold tabular-nums text-gray-900">{{ $row['coveredDays'] === 0 ? 'No forecast' : $money($row['revenue']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-[18px] py-6 text-center text-[13px] text-gray-500">No products yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <details class="rounded-lg border border-gray-200 bg-white px-[18px] py-3 text-[13px] text-gray-600 shadow-sm">
        <summary class="cursor-pointer select-none font-semibold text-gray-900">How these are calculated</summary>
        <dl class="mt-3 grid gap-2.5 sm:grid-cols-2">
            <div><dt class="font-semibold text-gray-800">Invoiced revenue</dt><dd>Issued, partially paid and paid invoices by issue date. Drafts and void invoices are excluded. Net is before VAT; gross includes it.</dd></div>
            <div><dt class="font-semibold text-gray-800">Collected</dt><dd>Payments by payment date. Reversed payments are excluded.</dd></div>
            <div><dt class="font-semibold text-gray-800">Outstanding</dt><dd>Invoice totals less payments received, for invoices not yet paid in full, as at the end of the period (or today).</dd></div>
            <div><dt class="font-semibold text-gray-800">Overdue</dt><dd>The part of outstanding on invoices whose due date had passed by that date. Due today is not overdue.</dd></div>
            <div><dt class="font-semibold text-gray-800">Change</dt><dd>Against the same number of days at the start of the previous month or quarter; last month against the month before; a custom range against the same length of time just before it.</dd></div>
            <div><dt class="font-semibold text-gray-800">Revenue by product</dt><dd>Each invoice's net total shared across its lines by line value, so product figures add up to invoiced net.</dd></div>
            <div><dt class="font-semibold text-gray-800">Aging</dt><dd>Open balances by days since the invoice was issued, as of today: 0–30, 31–60, 61–90 and over 90 days.</dd></div>
            <div><dt class="font-semibold text-gray-800">Expected revenue</dt><dd>The newest stored demand forecast for each of the next 7 days × the product's current price. Days without a forecast add nothing.</dd></div>
        </dl>
    </details>
</div>
