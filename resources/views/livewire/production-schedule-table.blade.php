<div>
    <div class="mb-6 grid grid-cols-1 gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-border bg-surface px-[18px] py-4">
            <div class="mb-2.5 flex items-center justify-between">
                <p class="text-[11.5px] font-bold uppercase tracking-[0.4px] text-muted">Today's Output</p>
                <span class="flex size-[26px] items-center justify-center rounded-md bg-accent-soft text-accent-ink" aria-hidden="true"><x-heroicon-o-cube class="size-4" /></span>
            </div>
            <p class="text-2xl font-extrabold text-fg">
                {{ number_format($kpis['output']) }}
                <span class="text-[13px] font-semibold text-muted">units</span>
            </p>
            <p class="mt-1 text-[11.5px] text-muted">Target: {{ number_format($kpis['outputTarget']) }}</p>
            <div class="mt-2.5 h-[5px] overflow-hidden rounded bg-surface-strong" role="progressbar" aria-valuenow="{{ $kpis['outputPct'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Today's output progress">
                <div class="h-full rounded bg-accent" style="width: {{ $kpis['outputPct'] }}%"></div>
            </div>
        </div>
        <div class="rounded-lg border border-border bg-surface px-[18px] py-4">
            <div class="mb-2.5 flex items-center justify-between">
                <p class="text-[11.5px] font-bold uppercase tracking-[0.4px] text-muted">Jobs Completed</p>
                <span class="flex size-[26px] items-center justify-center rounded-md bg-success-soft text-success-ink" aria-hidden="true"><x-heroicon-o-check-circle class="size-4" /></span>
            </div>
            <p class="text-2xl font-extrabold text-fg">
                {{ $kpis['completed'] }}
                <span class="text-[13px] font-semibold text-muted">/ {{ $kpis['totalJobs'] }}</span>
            </p>
            <p class="mt-1 text-[11.5px] {{ $kpis['completed'] === $kpis['totalJobs'] && $kpis['totalJobs'] > 0 ? 'text-success' : 'text-muted' }}">
                {{ $kpis['totalJobs'] > 0 ? ($kpis['completed'] === $kpis['totalJobs'] ? 'All jobs complete' : 'On schedule') : 'No jobs scheduled' }}
            </p>
            <div class="mt-2.5 h-[5px] overflow-hidden rounded bg-surface-strong" role="progressbar" aria-valuenow="{{ $kpis['jobsPct'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Jobs completed progress">
                <div class="h-full rounded bg-success" style="width: {{ $kpis['jobsPct'] }}%"></div>
            </div>
        </div>
        <div class="rounded-lg border border-border bg-surface px-[18px] py-4">
            <div class="mb-2.5 flex items-center justify-between">
                <p class="text-[11.5px] font-bold uppercase tracking-[0.4px] text-muted">Active Lines</p>
                <span class="flex size-[26px] items-center justify-center rounded-md bg-tag-soft text-tag-ink" aria-hidden="true"><x-heroicon-o-bolt class="size-4" /></span>
            </div>
            <p class="text-2xl font-extrabold text-fg">
                {{ $kpis['activeLines'] }}
                <span class="text-[13px] font-semibold text-muted">/ {{ $kpis['totalLines'] }}</span>
            </p>
            <p class="mt-1 text-[11.5px] text-muted">
                @if ($kpis['activeLineNames']->isNotEmpty())
                    {{ $kpis['activeLineNames']->join(', ') }} running
                @else
                    No lines running
                @endif
            </p>
        </div>
        <div class="rounded-lg border border-border bg-surface px-[18px] py-4">
            <div class="mb-2.5 flex items-center justify-between">
                <p class="text-[11.5px] font-bold uppercase tracking-[0.4px] text-muted">Forecast vs Actual</p>
                <span class="flex size-[26px] items-center justify-center rounded-md bg-warning-soft text-warning-ink" aria-hidden="true"><x-heroicon-o-presentation-chart-line class="size-4" /></span>
            </div>
            @if ($forecastComparison !== null && $forecastComparison['available'])
                <p class="text-2xl font-extrabold text-fg">
                    {{ ($forecastComparison['shortfallPct'] > 0 ? '+' : '').number_format($forecastComparison['shortfallPct'], 1) }}<span class="text-[13px]">%</span>
                </p>
                <p class="mt-1 text-[11.5px] {{ $forecastComparison['isBelow'] ? 'text-warning' : 'text-success' }}">
                    {{ $forecastComparison['isBelow'] ? 'Behind ML forecast' : 'Covers ML forecast' }}
                </p>
            @else
                <p class="text-2xl font-extrabold text-fg">—</p>
                <p class="mt-1 text-[11.5px] text-muted">Forecast unavailable</p>
            @endif
        </div>
    </div>

    <section aria-labelledby="schedule-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
        <h2 id="schedule-heading" class="sr-only">Production schedule for {{ app(\App\Support\SettingsDate::class)->formatDate($this->selectedDate) }}</h2>

        @if ($forecastComparison !== null && $forecastComparison['available'])
            <div @class([
                'flex items-center gap-2.5 border-b border-border px-[18px] py-3 text-[12.5px]',
                'border-warning/35 bg-warning-soft text-warning-ink' => $forecastComparison['isBelow'],
                'bg-accent-soft text-accent-ink' => ! $forecastComparison['isBelow'],
            ])>
                <x-heroicon-o-presentation-chart-line class="size-5 shrink-0" aria-hidden="true" />
                <p>
                    <b class="font-bold">ML Demand Forecast</b>
                    — suggested production for {{ $forecastComparison['sku'] }} is
                    <b class="font-bold">{{ number_format($forecastComparison['demand']) }} units</b>
                    from the stacked model. Current schedule targets {{ number_format($forecastComparison['target']) }}.
                </p>
                @if ($forecastComparison['isBelow'])
                    <span class="ml-auto inline-flex shrink-0 items-center gap-[5px] rounded-full bg-warning px-[9px] py-[3px] text-[11px] font-bold text-on-warning">
                        <span aria-hidden="true" class="size-1.5 rounded-full bg-surface"></span>
                        {{ number_format($forecastComparison['shortfall']) }} units ({{ number_format($forecastComparison['shortfallPct'], 1) }}%) below forecast
                    </span>
                @else
                    <span class="ml-auto inline-flex shrink-0 items-center gap-1 font-semibold">Covers forecast <x-heroicon-o-check-circle class="size-4" aria-hidden="true" /></span>
                @endif
            </div>
        @elseif ($forecastComparison !== null)
            <div class="flex items-center gap-2.5 border-b border-warning/35 bg-warning-soft px-[18px] py-3 text-[12.5px] text-warning-ink" role="status">
                <x-heroicon-o-exclamation-triangle class="size-5 shrink-0" aria-hidden="true" />
                <p>
                    <b class="font-bold">Forecast unavailable for {{ $forecastComparison['sku'] }}.</b>
                    The forecast client did not return a prediction for this SKU and date — this usually means
                    insufficient sales history (at least 14 consecutive days with confirmed sales-data coverage and
                    complete promotion, holiday, school-term, and temperature features are required), or the model is
                    not yet trained / the forecast service is unavailable.
                </p>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2 border-b border-border bg-surface-muted px-[18px] py-3">
            <label class="sr-only" for="schedule-date">Schedule date</label>
            <input
                id="schedule-date"
                type="date"
                wire:model.live="selectedDate"
                class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft"
            >
            <label class="sr-only" for="schedule-line-filter">Filter by line</label>
            <select id="schedule-line-filter" wire:model.live="lineFilter" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft">
                <option value="">Line: All</option>
                @foreach ($lines as $line)
                    <option value="{{ $line->id }}">Line: {{ $line->name }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="schedule-sku-filter">Filter by SKU</label>
            <select id="schedule-sku-filter" wire:model.live="skuFilter" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft">
                <option value="">SKU: All</option>
                @foreach ($skus as $sku)
                    <option value="{{ $sku }}">SKU: {{ $sku }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="schedule-status-filter">Filter by status</label>
            <select id="schedule-status-filter" wire:model.live="statusFilter" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft">
                <option value="">Status: All</option>
                <option value="queued">Status: Queued</option>
                <option value="active">Status: Active</option>
                <option value="complete">Status: Complete</option>
            </select>
            <p class="ml-auto text-xs text-muted">{{ \App\Support\Plural::count($schedules->count(), 'job') }} · {{ number_format($totalActual) }} / {{ number_format($totalTarget) }} units</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] border-collapse text-left">
                <caption class="sr-only">Scheduled production jobs with target, actual output, progress, and status</caption>
                <thead class="bg-surface-muted">
                    <tr>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Time</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">SKU</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Line</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Target</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Actual</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Progress</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Status</th>
                        <th scope="col" class="border-b border-border px-[18px] py-2.5"><span class="sr-only">Actions</span></th>
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
                        <tr wire:key="schedule-{{ $schedule->id }}" class="transition-colors hover:bg-surface-muted">
                            <td class="border-b border-border px-[18px] py-3 font-mono text-xs text-fg-soft">{{ $time }}</td>
                            <td class="border-b border-border px-[18px] py-3">
                                <span class="rounded bg-tag-soft px-2.5 py-[3px] text-[11px] font-bold text-tag-ink">{{ $schedule->product_sku }}</span>
                            </td>
                            <td class="border-b border-border px-[18px] py-3 text-[13px] text-fg-soft">{{ $schedule->productionLine?->name ?? '—' }}</td>
                            <td class="border-b border-border px-[18px] py-3 font-mono text-xs text-fg-soft">{{ number_format($targetUnits) }}</td>
                            <td class="border-b border-border px-[18px] py-3 font-mono text-xs text-fg-soft">{{ $actualUnits > 0 ? number_format($actualUnits) : '—' }}</td>
                            <td class="border-b border-border px-[18px] py-3">
                                <div class="flex min-w-[110px] items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded bg-surface-strong" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progress for {{ $schedule->product_sku }} at {{ $time }}">
                                        <div class="h-full rounded {{ $presentation['barClass'] }}" style="width: {{ $progress }}%"></div>
                                    </div>
                                    <span class="w-8 text-right text-[11.5px] font-semibold text-fg-soft">{{ $progress }}%</span>
                                </div>
                            </td>
                            <td class="border-b border-border px-[18px] py-3">
                                <span class="inline-flex items-center gap-[5px] rounded-full px-[9px] py-[3px] text-[11px] font-bold {{ $presentation['class'] }}">
                                    <span aria-hidden="true" class="size-1.5 rounded-full {{ $presentation['dotClass'] }}"></span>
                                    {{ $presentation['label'] }}
                                </span>
                            </td>
                            <td class="border-b border-border px-[18px] py-3">
                                <div class="flex justify-end gap-1.5">
                                    @if ($canLog)
                                        <button
                                            type="button"
                                            wire:click="$dispatch('open-log-modal', { scheduleId: {{ $schedule->id }} })"
                                            aria-label="Log production for {{ $schedule->product_sku }} at {{ $time }}"
                                            class="rounded-md border border-border-strong bg-surface px-2.5 py-1 text-xs font-semibold text-fg-soft hover:bg-surface-muted"
                                        >Log</button>
                                    @else
                                        <span class="text-xs text-muted">—</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-[18px] py-8 text-center text-sm text-muted">No scheduled jobs for this date and filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($activeRun !== null || ! empty($alerts))
        <h2 class="mb-3 mt-[26px] text-[15px] font-bold text-fg">Production Run Detail</h2>
        <div class="grid grid-cols-1 items-start gap-[18px] xl:grid-cols-[1.5fr_1fr]">
            <section aria-labelledby="run-detail-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
                @if ($activeRun !== null)
                    <div class="flex items-center justify-between gap-4 border-b border-border px-[18px] py-3.5">
                        <div>
                            <h3 id="run-detail-heading" class="text-sm font-bold text-fg">{{ $activeRun['sku'] }} — {{ $activeRun['line'] }}</h3>
                            <p class="mt-0.5 text-xs text-muted">Operator: {{ $activeRun['operator'] }} · Started {{ $activeRun['started'] }} · {{ number_format($activeRun['actual']) }} / {{ number_format($activeRun['target']) }} units ({{ $activeRun['progress'] }}%)</p>
                        </div>
                        <span class="inline-flex items-center gap-[5px] rounded-full bg-accent-soft px-[9px] py-[3px] text-[11px] font-bold text-accent-ink">
                            <span aria-hidden="true" class="size-1.5 rounded-full bg-accent"></span>
                            Active
                        </span>
                    </div>
                    <div class="mx-[18px] my-3.5 flex h-[210px] items-center justify-center rounded-md border border-dashed border-border-strong bg-gradient-to-b from-accent-soft to-transparent text-xs font-medium text-muted">
                        Output trend — actual vs target (last 4 hrs)
                    </div>
                    <div class="flex gap-2.5 px-[18px] pb-[18px]">
                        @if ($canLog)
                            <button type="button" wire:click="$dispatch('open-log-modal', { scheduleId: {{ $activeRun['scheduleId'] }} })" class="inline-flex flex-1 items-center justify-center rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">Log Production</button>
                        @else
                            <p class="flex-1 rounded-md bg-surface-strong px-3.5 py-2 text-center text-[13px] font-medium text-muted">Read-only access — logging requires full production access.</p>
                        @endif
                    </div>
                @endif
            </section>

            <section aria-labelledby="alerts-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-[18px] py-3.5">
                    <h3 id="alerts-heading" class="text-sm font-bold text-fg">Line Alerts</h3>
                </div>
                @forelse ($alerts as $alert)
                    <div class="flex gap-3 border-b border-border px-[18px] py-[13px] last:border-b-0">
                        <span aria-hidden="true" class="mt-[5px] size-[7px] shrink-0 rounded-full {{ $alert['color'] }}"></span>
                        <div>
                            <p class="text-[12.5px] text-fg-soft">{!! $alert['html'] !!}</p>
                            <p class="mt-0.5 text-[11px] text-muted">{{ $alert['time'] }}</p>
                        </div>
                    </div>
                @empty
                    <p class="px-[18px] py-6 text-center text-[12.5px] text-muted">No alerts for this date.</p>
                @endforelse
            </section>
        </div>
    @endif
</div>
