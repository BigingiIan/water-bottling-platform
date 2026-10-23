<div>
    @php
        $settingsDate = app(\App\Support\SettingsDate::class);
        $historySubtitle = $settingsDate->formatDate($this->dateFrom).' – '.$settingsDate->formatDate($this->dateTo)
            .' · '.number_format($totalActual).' / '.number_format($totalTarget).' units'
            .($totalVariancePct !== null ? ' ('.($totalVariancePct > 0 ? '+' : '').$totalVariancePct.'%)' : '');
    @endphp
    <x-page-header class="mb-6" title="Production History" :subtitle="$historySubtitle">
        <x-slot:actions>
            <button type="button" wire:click="exportCsv" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3.5 py-2 text-[13px] font-semibold text-gray-700 hover:bg-gray-50">
                <x-heroicon-o-arrow-down-tray class="size-4" aria-hidden="true" />
                Export CSV
            </button>
        </x-slot:actions>
    </x-page-header>

    <section aria-labelledby="history-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
        <h2 id="history-heading" class="sr-only">Production history</h2>

        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 bg-gray-50 px-[18px] py-3">
            <label class="sr-only" for="history-date-from">From date</label>
            <input
                id="history-date-from"
                type="date"
                wire:model.live="dateFrom"
                class="rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700"
            >
            <x-heroicon-o-arrow-right class="size-4 text-gray-400" aria-hidden="true" />
            <label class="sr-only" for="history-date-to">To date</label>
            <input
                id="history-date-to"
                type="date"
                wire:model.live="dateTo"
                class="rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700"
            >
            <label class="sr-only" for="history-sku-filter">Filter by SKU</label>
            <select id="history-sku-filter" wire:model.live="skuFilter" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600">
                <option value="">SKU: All</option>
                @foreach ($skus as $sku)
                    <option value="{{ $sku }}">SKU: {{ $sku }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="history-line-filter">Filter by line</label>
            <select id="history-line-filter" wire:model.live="lineFilter" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600">
                <option value="">Line: All</option>
                @foreach ($lines as $line)
                    <option value="{{ $line->id }}">Line: {{ $line->name }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="history-quality-filter">Filter by quality status</label>
            <select id="history-quality-filter" wire:model.live="qualityFilter" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600">
                <option value="">Quality: All</option>
                <option value="passed">Quality: Passed</option>
                <option value="flagged">Quality: Flagged</option>
            </select>
            <p class="ml-auto text-xs text-gray-500">{{ \App\Support\Plural::count($schedules->count(), 'job') }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] border-collapse text-left">
                <caption class="sr-only">Historical production jobs with final output, log entries, and quality flags</caption>
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Date</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Time</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">SKU</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Line</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Target</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Final Actual</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Variance</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Entries</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Flagged</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($schedules as $schedule)
                        @php
                            $presentation = $statusPresentation[$schedule->status] ?? $statusPresentation['queued'];
                            $time = mb_substr((string) $schedule->scheduled_time, 0, 5);
                            $historyTarget = (int) $schedule->target_units;
                            $historyActual = (int) ($schedule->logs_sum_units_logged ?? 0);
                            $historyVariance = $historyTarget > 0 ? round(($historyActual - $historyTarget) / $historyTarget * 100, 1) : null;
                        @endphp
                        <tr wire:key="history-{{ $schedule->id }}" class="transition-colors hover:bg-gray-50">
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ app(\App\Support\SettingsDate::class)->formatDate($schedule->scheduled_date) }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ $time }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3">
                                <span class="rounded bg-teal-50 px-2.5 py-[3px] text-[11px] font-bold text-[#0f766e]">{{ $schedule->product_sku }}</span>
                            </td>
                            <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700">{{ $schedule->productionLine?->name ?? '—' }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ number_format($historyTarget) }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ number_format($historyActual) }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs {{ $historyVariance === null ? 'text-gray-700' : ($historyVariance < 0 ? 'font-semibold text-amber-600' : 'font-semibold text-green-600') }}">{{ $historyVariance === null ? '—' : (($historyVariance > 0 ? '+' : '').$historyVariance.'%') }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ $schedule->logs_count }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs {{ $schedule->flagged_logs_count > 0 ? 'font-semibold text-amber-600' : 'text-gray-700' }}">{{ $schedule->flagged_logs_count }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3">
                                <span class="inline-flex items-center gap-[5px] rounded-full px-[9px] py-[3px] text-[11px] font-bold {{ $presentation['class'] }}">
                                    <span aria-hidden="true" class="size-1.5 rounded-full {{ $presentation['dotClass'] }}"></span>
                                    {{ $presentation['label'] }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-[18px] py-8 text-center text-sm text-gray-500">No production record for this date range and filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
