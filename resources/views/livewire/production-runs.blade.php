<div>
    <div class="mb-5 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-[21px] font-extrabold tracking-tight text-gray-900">Production Runs</h1>
            <p class="mt-1 text-[13px] text-gray-500">Open runs for {{ $this->selectedDate }} · {{ number_format($totalActual) }} / {{ number_format($totalTarget) }} units logged</p>
        </div>
    </div>

    <section aria-labelledby="runs-heading" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
        <h2 id="runs-heading" class="sr-only">Open production runs for {{ $this->selectedDate }}</h2>

        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 bg-gray-50 px-[18px] py-3">
            <label class="sr-only" for="runs-date">Runs date</label>
            <input
                id="runs-date"
                type="date"
                wire:model.live="selectedDate"
                class="rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700"
            >
            <label class="sr-only" for="runs-line-filter">Filter by line</label>
            <select id="runs-line-filter" wire:model.live="lineFilter" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600">
                <option value="">Line: All</option>
                @foreach ($lines as $line)
                    <option value="{{ $line->id }}">Line: {{ $line->name }}</option>
                @endforeach
            </select>
            <p class="ml-auto text-xs text-gray-500">{{ $runs->count() }} open runs</p>
        </div>

        @error('runTransition')
            <p role="alert" class="border-b border-amber-200 bg-amber-50 px-[18px] py-2.5 text-[12.5px] font-medium text-amber-900">{{ $message }}</p>
        @enderror

        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] border-collapse text-left">
                <caption class="sr-only">Open production runs with target, logged output, progress, and status</caption>
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
                    @forelse ($runs as $run)
                        @php
                            $presentation = $statusPresentation[$run->status] ?? $statusPresentation['queued'];
                            $actualUnits = (int) ($run->logs_sum_units_logged ?? 0);
                            $targetUnits = (int) $run->target_units;
                            $progress = $targetUnits > 0 ? min(100, (int) round($actualUnits / $targetUnits * 100)) : 0;
                            $time = mb_substr((string) $run->scheduled_time, 0, 5);
                        @endphp
                        <tr wire:key="run-{{ $run->id }}" class="transition-colors hover:bg-gray-50">
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ $time }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3">
                                <span class="rounded bg-teal-50 px-2.5 py-[3px] text-[11px] font-bold text-[#0f766e]">{{ $run->product_sku }}</span>
                            </td>
                            <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700">{{ $run->productionLine?->name ?? '—' }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ number_format($targetUnits) }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ $actualUnits > 0 ? number_format($actualUnits) : '—' }}</td>
                            <td class="border-b border-gray-100 px-[18px] py-3">
                                <div class="flex min-w-[110px] items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded bg-gray-100" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progress for {{ $run->product_sku }} at {{ $time }}">
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
                                    <button
                                        type="button"
                                        wire:click="selectRun({{ $run->id }})"
                                        aria-label="View details for {{ $run->product_sku }} at {{ $time }}"
                                        class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-50"
                                    >View</button>
                                    @if ($canLog)
                                        @if ($run->status === 'queued')
                                            <button
                                                type="button"
                                                wire:click="startRun({{ $run->id }})"
                                                aria-label="Start {{ $run->product_sku }} at {{ $time }}"
                                                class="rounded-md bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-blue-700"
                                            >Start</button>
                                        @elseif ($run->status === 'active')
                                            @if ($confirmingCompletion === $run->id)
                                                <button
                                                    type="button"
                                                    wire:click="completeRun({{ $run->id }})"
                                                    aria-label="Confirm completing {{ $run->product_sku }} at {{ $time }} with no logs"
                                                    class="rounded-md bg-amber-500 px-2.5 py-1 text-xs font-semibold text-white hover:bg-amber-600"
                                                >Confirm</button>
                                            @else
                                                <button
                                                    type="button"
                                                    wire:click="completeRun({{ $run->id }})"
                                                    aria-label="Complete {{ $run->product_sku }} at {{ $time }}"
                                                    class="rounded-md bg-green-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-green-700"
                                                >Complete</button>
                                            @endif
                                        @endif
                                        <button
                                            type="button"
                                            wire:click="$dispatch('open-log-modal', { scheduleId: {{ $run->id }} })"
                                            aria-label="Log production for {{ $run->product_sku }} at {{ $time }}"
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
                            <td colspan="8" class="px-[18px] py-8 text-center text-sm text-gray-500">No open runs for this date and filters.</td>
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
        <section aria-labelledby="run-detail-heading" class="mt-[18px] overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-[18px] py-3.5">
                <div>
                    <h2 id="run-detail-heading" class="text-sm font-bold text-gray-900">{{ $detailRun->product_sku }} — {{ $detailRun->productionLine?->name ?? '—' }}</h2>
                    <p class="mt-0.5 text-xs text-gray-500">{{ mb_substr((string) $detailRun->scheduled_time, 0, 5) }} · {{ number_format($detailActual) }} / {{ number_format($detailTarget) }} units · {{ $detailLogs->count() }} log entries</p>
                </div>
                <button type="button" wire:click="closeDetail" aria-label="Close run details" class="text-base text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] border-collapse text-left">
                    <caption class="sr-only">Log entries for {{ $detailRun->product_sku }}</caption>
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Logged At</th>
                            <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Operator</th>
                            <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Units</th>
                            <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Quality</th>
                            <th scope="col" class="border-b border-gray-200 px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-gray-500">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($detailLogs as $log)
                            <tr wire:key="run-log-{{ $log->id }}" class="transition-colors hover:bg-gray-50">
                                <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ $log->logged_at?->format('H:i') }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700">{{ $log->operator?->name ?? '—' }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 font-mono text-xs text-gray-700">{{ number_format((int) $log->units_logged) }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700">{{ $log->quality_status === 'flagged' ? 'Flagged for review' : 'Passed' }}</td>
                                <td class="border-b border-gray-100 px-[18px] py-3 text-[13px] text-gray-700">{{ $log->notes ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-[18px] py-8 text-center text-sm text-gray-500">No log entries for this run yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($detailRun->status === 'active')
                <div class="flex gap-2.5 px-[18px] py-[18px]">
                    @if ($canLog)
                        <button type="button" wire:click="$dispatch('open-log-modal', { scheduleId: {{ $detailRun->id }} })" aria-label="Log production on this run" class="inline-flex flex-1 items-center justify-center rounded-md bg-blue-600 px-3.5 py-2 text-[13px] font-semibold text-white hover:bg-blue-700">Log Production</button>
                    @else
                        <p class="flex-1 rounded-md bg-gray-100 px-3.5 py-2 text-center text-[13px] font-medium text-gray-500">Read-only access — logging requires full production access.</p>
                    @endif
                </div>
            @endif
        </section>
    @endif

    @unless ($canLog)
        <p class="mt-4 rounded-md bg-gray-100 px-3.5 py-2 text-center text-[13px] font-medium text-gray-500">Read-only access — logging requires full production access.</p>
    @endunless

    <livewire:log-production-modal />
</div>
