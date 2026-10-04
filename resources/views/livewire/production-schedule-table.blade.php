<div>
    <div class="mb-6 grid grid-cols-1 gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white px-[18px] py-4">
            <div class="mb-2.5 flex items-center justify-between">
                <p class="text-[11.5px] font-bold uppercase tracking-[0.4px] text-gray-500">Today's Output</p>
                <span class="flex size-[26px] items-center justify-center rounded-md bg-blue-50 text-[13px]" aria-hidden="true">📦</span>
            </div>
            <p class="text-2xl font-extrabold text-gray-900">
                {{ number_format($kpis['output']) }}
                <span class="text-[13px] font-semibold text-gray-400">units</span>
            </p>
            <p class="mt-1 text-[11.5px] text-gray-500">Target: {{ number_format($kpis['outputTarget']) }}</p>
            <div class="mt-2.5 h-[5px] overflow-hidden rounded bg-gray-100" role="progressbar" aria-valuenow="{{ $kpis['outputPct'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Today's output progress">
                <div class="h-full rounded bg-blue-500" style="width: {{ $kpis['outputPct'] }}%"></div>
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white px-[18px] py-4">
            <div class="mb-2.5 flex items-center justify-between">
                <p class="text-[11.5px] font-bold uppercase tracking-[0.4px] text-gray-500">Jobs Completed</p>
                <span class="flex size-[26px] items-center justify-center rounded-md bg-green-50 text-[13px]" aria-hidden="true">✓</span>
            </div>
            <p class="text-2xl font-extrabold text-gray-900">
                {{ $kpis['completed'] }}
                <span class="text-[13px] font-semibold text-gray-400">/ {{ $kpis['totalJobs'] }}</span>
            </p>
            <p class="mt-1 text-[11.5px] {{ $kpis['completed'] === $kpis['totalJobs'] && $kpis['totalJobs'] > 0 ? 'text-green-500' : 'text-gray-500' }}">
                {{ $kpis['totalJobs'] > 0 ? ($kpis['completed'] === $kpis['totalJobs'] ? 'All jobs complete' : 'On schedule') : 'No jobs scheduled' }}
            </p>
            <div class="mt-2.5 h-[5px] overflow-hidden rounded bg-gray-100" role="progressbar" aria-valuenow="{{ $kpis['jobsPct'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Jobs completed progress">
                <div class="h-full rounded bg-green-500" style="width: {{ $kpis['jobsPct'] }}%"></div>
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white px-[18px] py-4">
            <div class="mb-2.5 flex items-center justify-between">
                <p class="text-[11.5px] font-bold uppercase tracking-[0.4px] text-gray-500">Active Lines</p>
                <span class="flex size-[26px] items-center justify-center rounded-md bg-teal-50 text-[13px]" aria-hidden="true">⚙</span>
            </div>
            <p class="text-2xl font-extrabold text-gray-900">
                {{ $kpis['activeLines'] }}
                <span class="text-[13px] font-semibold text-gray-400">/ {{ $kpis['totalLines'] }}</span>
            </p>
            <p class="mt-1 text-[11.5px] text-gray-500">
                @if ($kpis['activeLineNames']->isNotEmpty())
                    {{ $kpis['activeLineNames']->join(', ') }} running
                @else
                    No lines running
                @endif
            </p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white px-[18px] py-4">
            <div class="mb-2.5 flex items-center justify-between">
                <p class="text-[11.5px] font-bold uppercase tracking-[0.4px] text-gray-500">Forecast vs Actual</p>
                <span class="flex size-[26px] items-center justify-center rounded-md bg-amber-50 text-[13px]" aria-hidden="true">📈</span>
            </div>
            @if ($forecastComparison !== null && $forecastComparison['available'])
                <p class="text-2xl font-extrabold text-gray-900">
                    {{ ($forecastComparison['shortfallPct'] > 0 ? '+' : '').number_format($forecastComparison['shortfallPct'], 1) }}<span class="text-[13px]">%</span>
                </p>
                <p class="mt-1 text-[11.5px] {{ $forecastComparison['isBelow'] ? 'text-amber-500' : 'text-green-500' }}">
                    {{ $forecastComparison['isBelow'] ? 'Behind ML forecast' : 'Covers ML forecast' }}
                </p>
            @else
                <p class="text-2xl font-extrabold text-gray-900">—</p>
                <p class="mt-1 text-[11.5px] text-gray-500">Forecast unavailable</p>
            @endif
        </div>
    </div>

    <section aria-labelledby="schedule-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
        <h2 id="schedule-heading" class="sr-only">Production schedule for {{ $this->selectedDate }}</h2>

        @if ($forecastComparison !== null && $forecastComparison['available'])
            <div @class([
                'flex items-center gap-2.5 border-b border-gray-200 px-[18px] py-3 text-[12.5px]',
                'border-amber-200 bg-amber-50 text-amber-900' => $forecastComparison['isBelow'],
                'bg-blue-50 text-[#1d4ed8]' => ! $forecastComparison['isBelow'],
            ])>
                <span aria-hidden="true">📈</span>
                <p>
                    <b class="font-bold">ML Demand Forecast</b>
                    — suggested production for {{ $forecastComparison['sku'] }} is
                    <b class="font-bold">{{ number_format($forecastComparison['demand']) }} units</b>
                    from the stacked model. Current schedule targets {{ number_format($forecastComparison['target']) }}.
                </p>
                @if ($forecastComparison['isBelow'])
                    <span class="ml-auto inline-flex shrink-0 items-center gap-[5px] rounded-full bg-amber-500 px-[9px] py-[3px] text-[11px] font-bold text-white">
                        <span aria-hidden="true" class="size-1.5 rounded-full bg-white"></span>
                        {{ number_format($forecastComparison['shortfall']) }} units ({{ number_format($forecastComparison['shortfallPct'], 1) }}%) below forecast
                    </span>
                @else
                    <span class="ml-auto shrink-0 font-semibold">Covers forecast ✓</span>
                @endif
            </div>
        @elseif ($forecastComparison !== null)
            <div class="flex items-center gap-2.5 border-b border-amber-200 bg-amber-50 px-[18px] py-3 text-[12.5px] text-amber-900" role="status">
                <span aria-hidden="true">⚠</span>
                <p>
                    <b class="font-bold">Forecast unavailable for {{ $forecastComparison['sku'] }}.</b>
                    The forecast client did not return a prediction for this SKU and date — this usually means
                    insufficient sales history (at least 14 consecutive days with confirmed sales-data coverage and
                    complete promotion, holiday, school-term, and temperature features are required), or the model is
                    not yet trained / the forecast service is unavailable.
                </p>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 bg-gray-50 px-[18px] py-3">
            <label class="sr-only" for="schedule-date">Schedule date</label>
            <input
                id="schedule-date"
                type="date"
                wire:model.live="selectedDate"
                class="rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700"
            >
            <label class="sr-only" for="schedule-line-filter">Filter by line</label>
            <select id="schedule-line-filter" wire:model.live="lineFilter" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600">
                <option value="">Line: All</option>
                @foreach ($lines as $line)
                    <option value="{{ $line->id }}">Line: {{ $line->name }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="schedule-sku-filter">Filter by SKU</label>
            <select id="schedule-sku-filter" wire:model.live="skuFilter" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600">
                <option value="">SKU: All</option>
                @foreach ($skus as $sku)
                    <option value="{{ $sku }}">SKU: {{ $sku }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="schedule-status-filter">Filter by status</label>
            <select id="schedule-status-filter" wire:model.live="statusFilter" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600">
                <option value="">Status: All</option>
                <option value="queued">Status: Queued</option>
                <option value="active">Status: Active</option>
                <option value="complete">Status: Complete</option>
            </select>
            <p class="ml-auto text-xs text-gray-500">{{ $schedules->count() }} jobs · {{ number_format($totalActual) }} / {{ number_format($totalTarget) }} units</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] border-collapse text-left">
                <caption class="sr-only">Scheduled production jobs with target, actual output, progress, and status</caption>
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Time</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">SKU</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Line</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Target</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Actual</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Progress</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Status</th>
                        <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($schedules as $schedule)
                        @php
                            $presentation = $statusPresentation[$schedule->status] ?? $statusPresentation['queued'];
                            $actualUnits = (int) ($schedule->logs_sum_units_logged ?? 0);
                            $targetUnits = (int) $schedule->target_units;
                            $progress = $targetUnits > 0 ? min(100, (int) round($actualUnits / $targetUnits * 100)) : 0;
                            $time = mb_substr((string) $schedule->scheduled_time, 0, 5);
                        @endphp
                        <tr wire:key="schedule-{{ $schedule->id }}" class="transition-colors hover:bg-gray-50">
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ $time }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3">
                                <span class="rounded bg-teal-50 px-2.5 py-[3px] text-[11px] font-bold text-[#0f766e]">{{ $schedule->product_sku }}</span>
                            </td>
                            <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700">{{ $schedule->productionLine?->name ?? '—' }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ number_format($targetUnits) }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ $actualUnits > 0 ? number_format($actualUnits) : '—' }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3">
                                <div class="flex min-w-[110px] items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded bg-gray-100" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progress for {{ $schedule->product_sku }} at {{ $time }}">
                                        <div class="h-full rounded {{ $presentation['barClass'] }}" style="width: {{ $progress }}%"></div>
                                    </div>
                                    <span class="w-8 text-right text-[11.5px] font-semibold text-gray-600">{{ $progress }}%</span>
                                </div>
                            </td>
                            <td class="border-b border-gray-100 px-[18px] py-3">
                                <span class="inline-flex items-center gap-[5px] rounded-full px-[9px] py-[3px] text-[11px] font-bold {{ $presentation['class'] }}">
                                    <span aria-hidden="true" class="size-1.5 rounded-full {{ $presentation['dotClass'] }}"></span>
                                    {{ $presentation['label'] }}
                                </span>
                            </td>
                            <td class="border-b border-gray-100 px-[18px] py-3">
                                <div class="flex justify-end gap-1.5">
                                    @if ($canLog)
                                        <button
                                            type="button"
                                            wire:click="$dispatch('open-log-modal', { scheduleId: {{ $schedule->id }} })"
                                            aria-label="Log production for {{ $schedule->product_sku }} at {{ $time }}"
                                            class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-50"
                                        >Log</button>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-[18px] py-8 text-center text-sm text-gray-500">No scheduled jobs for this date and filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($activeRun !== null || ! empty($alerts))
        <h2 class="mb-3 mt-[26px] text-[15px] font-bold text-gray-900">Production Run Detail</h2>
        <div class="grid grid-cols-1 items-start gap-[18px] xl:grid-cols-[1.5fr_1fr]">
            <section aria-labelledby="run-detail-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                @if ($activeRun !== null)
                    <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-[18px] py-3.5">
                        <div>
                            <h3 id="run-detail-heading" class="text-sm font-bold text-gray-900">{{ $activeRun['sku'] }} — {{ $activeRun['line'] }}</h3>
                            <p class="mt-0.5 text-xs text-gray-500">Operator: {{ $activeRun['operator'] }} · Started {{ $activeRun['started'] }} · {{ number_format($activeRun['actual']) }} / {{ number_format($activeRun['target']) }} units ({{ $activeRun['progress'] }}%)</p>
                        </div>
                        <span class="inline-flex items-center gap-[5px] rounded-full bg-blue-50 px-[9px] py-[3px] text-[11px] font-bold text-[#1d4ed8]">
                            <span aria-hidden="true" class="size-1.5 rounded-full bg-blue-500"></span>
                            Active
                        </span>
                    </div>
                    <div class="mx-[18px] my-3.5 flex h-[210px] items-center justify-center rounded-md border border-dashed border-gray-300 bg-gradient-to-b from-blue-50 to-transparent text-xs font-medium text-gray-400">
                        Output trend — actual vs target (last 4 hrs)
                    </div>
                    <div class="flex gap-2.5 px-[18px] pb-[18px]">
                        @if ($canLog)
                            <button type="button" wire:click="$dispatch('open-log-modal', { scheduleId: {{ $activeRun['scheduleId'] }} })" class="inline-flex flex-1 items-center justify-center rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700">Log Production</button>
                        @else
                            <p class="flex-1 rounded-md bg-gray-100 px-3.5 py-2 text-center text-[13px] font-medium text-gray-500">Read-only access — logging requires full production access.</p>
                        @endif
                    </div>
                @endif
            </section>

            <section aria-labelledby="alerts-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-[18px] py-3.5">
                    <h3 id="alerts-heading" class="text-sm font-bold text-gray-900">Line Alerts</h3>
                </div>
                @forelse ($alerts as $alert)
                    <div class="flex gap-3 border-b border-gray-100 px-[18px] py-[13px] last:border-b-0">
                        <span aria-hidden="true" class="mt-[5px] size-[7px] shrink-0 rounded-full {{ $alert['color'] }}"></span>
                        <div>
                            <p class="text-[12.5px] text-gray-700">{!! $alert['html'] !!}</p>
                            <p class="mt-0.5 text-[11px] text-gray-400">{{ $alert['time'] }}</p>
                        </div>
                    </div>
                @empty
                    <p class="px-[18px] py-6 text-center text-[12.5px] text-gray-500">No alerts for this date.</p>
                @endforelse
            </section>
        </div>
    @endif
</div>
