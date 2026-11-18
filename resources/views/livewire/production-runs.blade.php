<div>
    <x-page-header help="production"
        class="mb-6"
        title="Production Runs"
        :subtitle="'Open runs for '.app(\App\Support\SettingsDate::class)->formatDate($this->selectedDate).' · '.number_format($totalActual).' / '.number_format($totalTarget).' units logged'"
    />

    @if ($completionResult)
        <p role="status" class="mb-4 flex items-start gap-2 rounded-md border border-success/35 bg-success-soft px-4 py-2.5 text-sm font-medium text-success-ink">
            <x-heroicon-o-check-circle class="mt-px size-5 shrink-0" aria-hidden="true" />
            <span>{{ $completionResult }} @if (str_contains($completionResult, 'leakage report')) <a href="{{ route('inventory.overview', ['tab' => 'leakage']) }}" class="font-semibold underline">View leakage reports</a> @endif</span>
        </p>
    @endif

    <section aria-labelledby="runs-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
        <h2 id="runs-heading" class="sr-only">Open production runs for {{ app(\App\Support\SettingsDate::class)->formatDate($this->selectedDate) }}</h2>

        <div class="flex flex-wrap items-center gap-2 border-b border-border bg-surface-muted px-[18px] py-3">
            <label class="sr-only" for="runs-date">Runs date</label>
            <input
                id="runs-date"
                type="date"
                wire:model.live="selectedDate"
                class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft"
            >
            <label class="sr-only" for="runs-line-filter">Filter by line</label>
            <select id="runs-line-filter" wire:model.live="lineFilter" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft">
                <option value="">Line: All</option>
                @foreach ($lines as $line)
                    <option value="{{ $line->id }}">Line: {{ $line->name }}</option>
                @endforeach
            </select>
            <p class="ml-auto text-xs text-muted">{{ \App\Support\Plural::count($runs->count(), 'open run') }}</p>
        </div>

        @error('runTransition')
            <p role="alert" class="border-b border-warning/35 bg-warning-soft px-[18px] py-2.5 text-[12.5px] font-medium text-warning-ink">{{ $message }}</p>
        @enderror

        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] border-collapse text-left">
                <caption class="sr-only">Open production runs with target, logged output, progress, and status</caption>
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
                    @forelse ($runs as $run)
                        @php
                            $presentation = $statusPresentation[$run->status] ?? $statusPresentation['queued'];
                            $actualUnits = (int) ($run->logs_sum_units_logged ?? 0);
                            $targetUnits = (int) $run->target_units;
                            $progress = $targetUnits > 0 ? min(100, (int) round($actualUnits / $targetUnits * 100)) : 0;
                            $time = mb_substr((string) $run->scheduled_time, 0, 5);
                        @endphp
                        <tr wire:key="run-{{ $run->id }}" class="transition-colors hover:bg-surface-muted">
                            <td class="border-b border-border px-[18px] py-3 font-mono text-xs text-fg-soft">{{ $time }}</td>
                            <td class="border-b border-border px-[18px] py-3">
                                <span class="rounded bg-tag-soft px-2.5 py-[3px] text-[11px] font-bold text-tag-ink">{{ $run->product_sku }}</span>
                            </td>
                            <td class="border-b border-border px-[18px] py-3 text-[13px] text-fg-soft">{{ $run->productionLine?->name ?? '—' }}</td>
                            <td class="border-b border-border px-[18px] py-3 font-mono text-xs text-fg-soft">{{ number_format($targetUnits) }}</td>
                            <td class="border-b border-border px-[18px] py-3 font-mono text-xs text-fg-soft">{{ $actualUnits > 0 ? number_format($actualUnits) : '—' }}</td>
                            <td class="border-b border-border px-[18px] py-3">
                                <div class="flex min-w-[110px] items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded bg-surface-strong" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progress for {{ $run->product_sku }} at {{ $time }}">
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
                                    <button
                                        type="button"
                                        wire:click="selectRun({{ $run->id }})"
                                        aria-label="View details for {{ $run->product_sku }} at {{ $time }}"
                                        class="rounded-md border border-border-strong bg-surface px-2.5 py-1 text-xs font-semibold text-fg-soft hover:bg-surface-muted"
                                    >View</button>
                                    @if ($canLog)
                                        @if ($run->status === 'queued')
                                            <button
                                                type="button"
                                                wire:click="startRun({{ $run->id }})"
                                                aria-label="Start {{ $run->product_sku }} at {{ $time }}"
                                                class="rounded-md bg-accent px-2.5 py-1 text-xs font-semibold text-on-accent hover:bg-accent-hover"
                                            >Start</button>
                                        @elseif ($run->status === 'active')
                                            <button
                                                type="button"
                                                wire:click="completeRun({{ $run->id }})"
                                                aria-label="Complete {{ $run->product_sku }} at {{ $time }}"
                                                class="rounded-md bg-success px-2.5 py-1 text-xs font-semibold text-on-success hover:bg-success-hover"
                                            >Complete</button>
                                        @endif
                                        <button
                                            type="button"
                                            wire:click="$dispatch('open-log-modal', { scheduleId: {{ $run->id }} })"
                                            aria-label="Log production for {{ $run->product_sku }} at {{ $time }}"
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
                            <td colspan="8"><x-empty-state key="production_runs" icon="play-circle" :filtered="$lineFilter !== ''" :can="$canLog" :action-href="route('production.schedule')" /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($selectedRun !== null)
        @php
            $detailRun = $selectedRun['run'];
            $detailLogs = $selectedRun['logs'];
            $detailActual = (int) ($detailRun->logs_sum_units_logged ?? 0);
            $detailTarget = (int) $detailRun->target_units;
        @endphp
        <section aria-labelledby="run-detail-heading" class="mt-[18px] overflow-hidden rounded-lg border border-border bg-surface">
            <div class="flex items-center justify-between gap-4 border-b border-border px-[18px] py-3.5">
                <div>
                    <h2 id="run-detail-heading" class="text-sm font-bold text-fg">{{ $detailRun->product_sku }} — {{ $detailRun->productionLine?->name ?? '—' }}</h2>
                    <p class="mt-0.5 text-xs text-muted">{{ mb_substr((string) $detailRun->scheduled_time, 0, 5) }} · {{ number_format($detailActual) }} / {{ number_format($detailTarget) }} units · {{ \App\Support\Plural::count($detailLogs->count(), 'log entry') }}</p>
                </div>
                <button type="button" wire:click="closeDetail" aria-label="Close run details" class="rounded-md p-1 text-muted hover:bg-surface-strong hover:text-fg-soft"><x-heroicon-o-x-mark class="size-5" aria-hidden="true" /></button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] border-collapse text-left">
                    <caption class="sr-only">Log entries for {{ $detailRun->product_sku }}</caption>
                    <thead class="bg-surface-muted">
                        <tr>
                            <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Logged At</th>
                            <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Operator</th>
                            <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Units</th>
                            <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Quality</th>
                            <th scope="col" class="border-b border-border px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($detailLogs as $log)
                            <tr wire:key="run-log-{{ $log->id }}" class="transition-colors hover:bg-surface-muted">
                                <td class="border-b border-border px-[18px] py-3 font-mono text-xs text-fg-soft">{{ app(\App\Support\SettingsDate::class)->formatTime($log->logged_at) }}</td>
                                <td class="border-b border-border px-[18px] py-3 text-[13px] text-fg-soft">{{ $log->operator?->name ?? '—' }}</td>
                                <td class="border-b border-border px-[18px] py-3 font-mono text-xs text-fg-soft">{{ number_format((int) $log->units_logged) }}</td>
                                <td class="border-b border-border px-[18px] py-3 text-[13px] text-fg-soft">{{ $log->quality_status === 'flagged' ? 'Flagged for review' : 'Passed' }}</td>
                                <td class="border-b border-border px-[18px] py-3 text-[13px] text-fg-soft">{{ $log->notes ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-[18px] py-8 text-center text-sm text-muted">No log entries for this run yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (app(\App\Services\Requests\RequestAccess::class)->canSendRequests(auth()->user()))
                <div class="flex justify-end border-t border-border px-[18px] py-3">
                    <x-send-request-button related-type="production_run" :related-id="$detailRun->id" label="Send request about this run" />
                </div>
            @endif
            @if ($detailRun->status === 'active')
                <div class="flex gap-2.5 px-[18px] py-[18px]">
                    @if ($canLog)
                        <button type="button" wire:click="$dispatch('open-log-modal', { scheduleId: {{ $detailRun->id }} })" aria-label="Log production on this run" class="inline-flex flex-1 items-center justify-center rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">Log Production</button>
                    @else
                        <p class="flex-1 rounded-md bg-surface-strong px-3.5 py-2 text-center text-[13px] font-medium text-muted">Read-only access — logging requires full production access.</p>
                    @endif
                </div>
            @endif
        </section>
    @endif

    @unless ($canLog)
        <p class="mt-4 rounded-md bg-surface-strong px-3.5 py-2 text-center text-[13px] font-medium text-muted">Read-only access — logging requires full production access.</p>
    @endunless

    @if ($completingRunId)
        @php
            $completingRun = $runs->firstWhere('id', $completingRunId) ?? \App\Models\ProductionSchedule::query()->withSum('logs', 'units_logged')->find($completingRunId);
            $completingUnits = (int) ($completingRun?->logs_sum_units_logged ?? 0);
        @endphp
        <x-modal-dialog id="complete-run" title="Complete run" close="closeCompletion">
            <p class="text-[13px] text-fg-soft">
                <span class="font-semibold text-fg">{{ $completingRun?->product_sku }}</span> on {{ $completingRun?->productionLine?->name ?? 'its line' }}
                · {{ \App\Support\Plural::count($completingUnits, 'unit') }} logged. Completing the run completes its batch and checks it for process loss.
            </p>
            @if ($completingUnits === 0)
                <p role="alert" class="flex items-start gap-2 rounded-md border border-warning/35 bg-warning-soft px-3 py-2 text-[12.5px] text-warning-ink">
                    <x-heroicon-o-exclamation-triangle class="mt-px size-4 shrink-0" aria-hidden="true" />
                    No production has been logged on this run, so all the water will count as lost.
                </p>
            @endif
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="complete-raw-water" class="mb-1.5 block text-xs font-semibold text-fg-soft">Raw water used (L)</label>
                    <input id="complete-raw-water" type="number" min="0" step="0.001" wire:model="rawWaterVolume" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                    @error('rawWaterVolume') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="complete-metered" class="mb-1.5 block text-xs font-semibold text-fg-soft">Metered bottled volume (L)</label>
                    <input id="complete-metered" type="number" min="0" step="0.001" wire:model="meteredBottledVolume" class="w-full rounded-md border border-border-strong px-2.5 py-2 text-[13px] text-fg">
                    @error('meteredBottledVolume') <p role="alert" class="mt-1 text-[11px] text-danger-ink">{{ $message }}</p> @enderror
                </div>
            </div>
            <x-slot:footer>
                <button type="button" wire:click="closeCompletion" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft">Cancel</button>
                <button type="button" wire:click="finishCompletion" wire:loading.attr="disabled" class="rounded-md bg-success px-3.5 py-2 text-[13px] font-semibold text-on-success hover:bg-success-hover disabled:opacity-60">Complete run</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif

    <livewire:log-production-modal />
</div>
