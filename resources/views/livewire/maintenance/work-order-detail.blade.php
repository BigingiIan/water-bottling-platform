@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $equipment = $workOrder->equipment;
    $inputClasses = 'w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-[13px] text-fg focus:border-accent focus:outline-none focus:ring-2 focus:ring-focus/20';
    $labelClasses = 'mb-1 block text-xs font-semibold text-fg-soft';
    $results = (array) $workOrder->checklist_results;
@endphp

<div class="flex flex-col gap-6">
    <div>
        <a href="{{ route('maintenance.work-orders.index') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-accent-ink hover:text-accent-ink">
            <x-heroicon-o-arrow-left class="size-3.5" aria-hidden="true" />
            All work orders
        </a>
    </div>

    <x-page-header help="maintenance" :title="$workOrder->reference.' · '.$equipment->name" :subtitle="__('maintenance.work_order_types.'.$workOrder->type).' · '.__('maintenance.work_order_sources.'.$workOrder->source).' · reported '.$workOrder->created_at->diffForHumans()">
        <x-slot:actions>
            <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-bold {{ \App\Models\WorkOrder::statusClasses($workOrder->status) }}">{{ __('maintenance.work_order_statuses.'.$workOrder->status) }}</span>
            @if ($canManage && $workOrder->status === 'open')
                <button type="button" wire:click="start" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted"><x-heroicon-o-play class="size-4" aria-hidden="true" />Start work</button>
            @endif
            @if ($canManage && $workOrder->isActive())
                <button type="button" wire:click="openComplete" class="inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover"><x-heroicon-o-check-circle class="size-4" aria-hidden="true" />Complete</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="flex min-w-0 flex-col gap-6">
            <section aria-labelledby="wo-description-heading" class="rounded-lg border border-border bg-surface">
                <h2 id="wo-description-heading" class="border-b border-border px-[18px] py-3.5 text-sm font-bold text-fg">What needs doing</h2>
                <p class="whitespace-pre-line px-[18px] py-4 text-sm leading-6 text-fg">{{ $workOrder->description }}</p>
                @if ($workOrder->request)
                    <p class="border-t border-border px-[18px] py-3 text-xs text-fg-soft">From request <a href="{{ route('requests.show', $workOrder->request_id) }}" class="font-semibold text-accent-ink hover:underline">{{ $workOrder->request->reference }}</a>: {{ $workOrder->request->subject }}</p>
                @endif
            </section>

            <section aria-labelledby="wo-checklist-heading" class="rounded-lg border border-border bg-surface">
                <h2 id="wo-checklist-heading" class="border-b border-border px-[18px] py-3.5 text-sm font-bold text-fg">Checklist</h2>
                @if ($steps === [])
                    <p class="px-[18px] py-4 text-sm text-muted">No checklist for this work order.</p>
                @else
                    <ol class="divide-y divide-border">
                        @foreach ($steps as $index => $step)
                            @php
                                $done = (bool) ($results[$index] ?? false);
                            @endphp
                            <li class="flex items-center gap-2.5 px-[18px] py-2.5 text-[13px] text-fg">
                                @if ($workOrder->status === 'done')
                                    <x-dynamic-component :component="$done ? 'heroicon-o-check-circle' : 'heroicon-o-minus-circle'" @class(['size-5 shrink-0', 'text-success-ink' => $done, 'text-subtle' => ! $done]) aria-hidden="true" />
                                    <span>{{ $step }} <span class="sr-only">{{ $done ? '(done)' : '(not done)' }}</span></span>
                                @else
                                    <span aria-hidden="true" class="flex size-5 shrink-0 items-center justify-center rounded-full bg-surface-strong text-[11px] font-bold text-fg-soft">{{ $index + 1 }}</span>
                                    <span>{{ $step }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            @if ($workOrder->status === 'done' || $workOrder->status === 'cancelled')
                <section aria-labelledby="wo-result-heading" class="rounded-lg border border-border bg-surface">
                    <h2 id="wo-result-heading" class="border-b border-border px-[18px] py-3.5 text-sm font-bold text-fg">{{ $workOrder->status === 'done' ? 'Completion' : 'Cancelled' }}</h2>
                    <dl class="grid gap-x-6 gap-y-3 px-[18px] py-4 text-[13px] sm:grid-cols-2">
                        @if ($workOrder->status === 'done')
                            <div><dt class="text-xs text-muted">Downtime</dt><dd class="font-semibold text-fg">{{ number_format((int) $workOrder->downtime_minutes) }} min</dd></div>
                            <div><dt class="text-xs text-muted">Cost</dt><dd class="font-semibold text-fg">{{ $workOrder->cost_kes === null ? '—' : \App\Services\Orders\Money::format((string) $workOrder->cost_kes) }}</dd></div>
                            <div class="sm:col-span-2"><dt class="text-xs text-muted">Parts used</dt><dd class="whitespace-pre-line text-fg">{{ $workOrder->parts_used ?: '—' }}</dd></div>
                        @endif
                        <div class="sm:col-span-2"><dt class="text-xs text-muted">Notes</dt><dd class="whitespace-pre-line text-fg">{{ $workOrder->completion_notes ?: '—' }}</dd></div>
                    </dl>
                </section>
            @endif
        </div>

        <aside class="flex flex-col gap-4">
            <section aria-labelledby="wo-details-heading" class="rounded-lg border border-border bg-surface">
                <h2 id="wo-details-heading" class="border-b border-border px-[18px] py-3.5 text-sm font-bold text-fg">Details</h2>
                <dl class="grid gap-3 px-[18px] py-4 text-[13px]">
                    <div class="flex justify-between gap-3"><dt class="text-muted">Equipment</dt><dd class="text-right"><a href="{{ route('maintenance.equipment.show', $equipment) }}" class="font-semibold text-accent-ink hover:underline">{{ $equipment->code }}</a></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Line</dt><dd class="text-right text-fg">{{ $equipment->productionLine?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Equipment status</dt><dd><x-equipment-status-badge :status="$equipment->status" /></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Priority</dt><dd class="text-right font-semibold text-fg">{{ __('maintenance.priorities.'.$workOrder->priority) }}</dd></div>
                    @if ($workOrder->due_on)
                        <div class="flex justify-between gap-3"><dt class="text-muted">Due</dt><dd class="text-right text-fg">{{ $settingsDate->formatDate($workOrder->due_on) }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-3"><dt class="text-muted">Reported by</dt><dd class="text-right text-fg">{{ $workOrder->reporter?->name ?? 'Maintenance plan' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Started</dt><dd class="text-right text-fg">{{ $workOrder->started_at ? $settingsDate->formatDateTime($workOrder->started_at) : '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Completed</dt><dd class="text-right text-fg">{{ $workOrder->completed_at ? $settingsDate->formatDateTime($workOrder->completed_at) : '—' }}</dd></div>
                </dl>
            </section>

            @if ($canManage && $workOrder->isActive())
                <section aria-labelledby="wo-assign-heading" class="rounded-lg border border-border bg-surface">
                    <h2 id="wo-assign-heading" class="border-b border-border px-[18px] py-3.5 text-sm font-bold text-fg">Assigned to</h2>
                    <form wire:submit="assign" class="flex flex-col gap-2 px-[18px] py-4">
                        <label for="wo-assign" class="sr-only">Assign to</label>
                        <select id="wo-assign" wire:model="assignTo" class="{{ $inputClasses }}">
                            <option value="">Nobody</option>
                            @foreach ($assignees as $assignee)
                                <option value="{{ $assignee->id }}">{{ $assignee->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-xs font-semibold text-fg-soft hover:bg-surface-muted">Save assignment</button>
                    </form>
                    <div class="border-t border-border px-[18px] py-3">
                        <button type="button" wire:click="$set('cancelling', true)" class="text-xs font-semibold text-danger-ink hover:text-danger-ink">Cancel this work order</button>
                    </div>
                </section>
            @else
                <p class="rounded-lg border border-border bg-surface px-[18px] py-3.5 text-[13px] text-fg-soft">Assigned to <span class="font-semibold">{{ $workOrder->assignee?->name ?? 'nobody' }}</span></p>
            @endif
        </aside>
    </div>

    @if ($completing)
        <x-modal-dialog id="wo-complete" :title="'Complete '.$workOrder->reference" close="closeComplete" max-width="max-w-[560px]">
            <form id="wo-complete-form" wire:submit="complete" class="flex flex-col gap-3.5">
                @if ($steps !== [])
                    <fieldset>
                        <legend class="{{ $labelClasses }}">Checklist</legend>
                        <div class="flex flex-col gap-1.5">
                            @foreach ($steps as $index => $step)
                                <label class="flex items-center gap-2 text-[13px] text-fg"><input type="checkbox" wire:model="checklistResults.{{ $index }}" class="size-4 rounded border-border-strong text-accent-ink"> {{ $step }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
                <div class="grid gap-3.5 sm:grid-cols-2">
                    <div>
                        <label for="wo-downtime" class="{{ $labelClasses }}">Downtime (minutes)</label>
                        <input id="wo-downtime" type="number" min="0" wire:model="downtimeMinutes" class="{{ $inputClasses }}">
                        @error('downtime_minutes')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="wo-cost" class="{{ $labelClasses }}">Cost (KES, optional)</label>
                        <input id="wo-cost" type="number" min="0" step="0.01" wire:model="costKes" class="{{ $inputClasses }}">
                        @error('cost_kes')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div>
                    <label for="wo-parts" class="{{ $labelClasses }}">Parts used</label>
                    <textarea id="wo-parts" rows="2" wire:model="partsUsed" class="{{ $inputClasses }}"></textarea>
                </div>
                <div>
                    <label for="wo-notes" class="{{ $labelClasses }}">Notes</label>
                    <textarea id="wo-notes" rows="3" wire:model="completionNotes" class="{{ $inputClasses }}"></textarea>
                </div>
                @unless ($equipment->isOperational())
                    <fieldset class="rounded-md border border-warning/35 bg-warning-soft p-3">
                        <legend class="px-1 text-xs font-semibold text-warning-ink">{{ __('maintenance.complete.return_question', ['equipment' => $equipment->name, 'status' => strtolower($equipment->statusLabel())]) }}</legend>
                        <div class="mt-1 flex flex-col gap-1.5 text-[13px] text-warning-ink">
                            <label class="flex items-center gap-2"><input type="radio" wire:model="returnToOperational" value="1" class="size-4 text-accent-ink"> {{ __('maintenance.complete.return_yes') }}</label>
                            <label class="flex items-center gap-2"><input type="radio" wire:model="returnToOperational" value="0" class="size-4 text-accent-ink"> {{ __('maintenance.complete.return_no') }}</label>
                        </div>
                        @error('return_to_operational')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </fieldset>
                @endunless
            </form>
            <x-slot:footer>
                <button type="button" wire:click="closeComplete" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Back</button>
                <button type="submit" form="wo-complete-form" class="rounded-md bg-success px-3.5 py-2 text-[13px] font-semibold text-on-success hover:bg-success-hover">Mark done</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif

    @if ($cancelling)
        <x-modal-dialog id="wo-cancel" :title="'Cancel '.$workOrder->reference.'?'" close="closeCancel">
            <label for="wo-cancel-reason" class="{{ $labelClasses }}">Why is it cancelled?</label>
            <textarea id="wo-cancel-reason" rows="3" wire:model="cancelReason" class="{{ $inputClasses }}"></textarea>
            @error('reason')<p role="alert" class="text-xs text-danger-ink">{{ $message }}</p>@enderror
            <x-slot:footer>
                <button type="button" wire:click="closeCancel" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Keep it</button>
                <button type="button" wire:click="cancelWorkOrder" class="rounded-md bg-danger px-3.5 py-2 text-[13px] font-semibold text-on-danger hover:bg-danger-hover">Cancel work order</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif
</div>
