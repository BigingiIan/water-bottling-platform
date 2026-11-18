@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $thClasses = 'border-b border-border bg-surface-muted px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted';
    $inputClasses = 'w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-[13px] text-fg focus:border-accent focus:outline-none focus:ring-2 focus:ring-focus/20';
    $labelClasses = 'mb-1 block text-xs font-semibold text-fg-soft';
    $secondaryButton = 'inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted';
@endphp

<div class="flex flex-col gap-6">
    <div>
        <a href="{{ route('maintenance.equipment.index') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-accent-ink hover:text-accent-ink">
            <x-heroicon-o-arrow-left class="size-3.5" aria-hidden="true" />
            All equipment
        </a>
    </div>

    <x-page-header help="maintenance" :title="$equipment->name" :subtitle="$equipment->code.' · '.__('maintenance.equipment_types.'.$equipment->type).' · '.($equipment->productionLine?->name ?? 'Not on a line')">
        <x-slot:actions>
            <x-equipment-status-badge :status="$equipment->status" class="mr-1" />
            @if ($canSendRequests)
                <x-send-request-button related-type="equipment" :related-id="$equipment->id" />
            @endif
            @if ($canManage)
                <button type="button" wire:click="openStatus" class="{{ $secondaryButton }}"><x-heroicon-o-arrow-path class="size-4" aria-hidden="true" />Change status</button>
                <button type="button" wire:click="openBreakdown" class="inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover"><x-heroicon-o-exclamation-triangle class="size-4" aria-hidden="true" />Report a breakdown</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <dl class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['Downtime, last 30 days', number_format($downtime30).' min'],
            ['Downtime, last 90 days', number_format($downtime90).' min'],
            ['Location', $equipment->location ?: '—'],
            ['Make and model', trim(($equipment->manufacturer ?? '').' '.($equipment->model ?? '')) ?: '—'],
        ] as [$label, $value])
            <div class="rounded-lg border border-border bg-surface px-[18px] py-4">
                <dt class="text-[11px] font-bold uppercase tracking-[0.4px] text-muted">{{ $label }}</dt>
                <dd class="mt-1.5 text-lg font-extrabold tabular-nums text-fg">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <section aria-labelledby="plans-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
        <div class="flex items-center justify-between gap-3 border-b border-border px-[18px] py-3.5">
            <div>
                <h2 id="plans-heading" class="text-sm font-bold text-fg">Preventive maintenance plans</h2>
                <p class="mt-0.5 text-xs text-muted">A work order is created automatically two days before each task is due.</p>
            </div>
            @if ($canManage)
                <button type="button" wire:click="openPlan" class="text-xs font-semibold text-accent-ink hover:text-accent-ink">Add plan</button>
            @endif
        </div>
        @if ($plans->isEmpty())
            <p class="px-[18px] py-6 text-sm text-muted">{{ __('maintenance.empty.plans') }}</p>
        @else
            <ul class="divide-y divide-border">
                @foreach ($plans as $plan)
                    <li wire:key="plan-{{ $plan->id }}" class="flex flex-wrap items-start justify-between gap-3 px-[18px] py-3 text-[13px]">
                        <div class="min-w-0">
                            <p class="font-semibold text-fg">{{ $plan->task }} @unless ($plan->active)<span class="ml-1 rounded-full bg-surface-strong px-2 py-0.5 text-[10.5px] font-bold text-muted">Paused</span>@endunless</p>
                            <p class="mt-0.5 text-xs text-muted">
                                Every {{ \App\Support\Plural::count($plan->every_days, 'day') }} · {{ $plan->assignedRole?->name ?? 'Any maintenance user' }}
                                · last done {{ $plan->last_done_on ? $settingsDate->formatDate($plan->last_done_on) : 'never' }}
                                · {{ \App\Support\Plural::count(count($plan->checklistSteps()), 'checklist step') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span @class(['whitespace-nowrap text-xs font-semibold', 'text-danger-ink' => $plan->isOverdue(), 'text-fg-soft' => ! $plan->isOverdue()])>
                                {{ $plan->isOverdue() ? 'Overdue since' : 'Next due' }} {{ $settingsDate->formatDate($plan->next_due_on) }}
                            </span>
                            @if ($canManage)
                                <button type="button" wire:click="openPlan({{ $plan->id }})" aria-label="Edit plan {{ $plan->task }}" class="text-xs font-semibold text-accent-ink hover:text-accent-ink">Edit</button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="history-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
        <h2 id="history-heading" class="border-b border-border px-[18px] py-3.5 text-sm font-bold text-fg">Work-order history</h2>
        @if ($workOrders->isEmpty())
            <p class="px-[18px] py-6 text-sm text-muted">{{ __('maintenance.empty.history') }}</p>
        @else
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[720px] border-separate border-spacing-0 text-left text-[13px]">
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $thClasses }}">Work order</th>
                            <th scope="col" class="{{ $thClasses }}">Type</th>
                            <th scope="col" class="{{ $thClasses }}">Status</th>
                            <th scope="col" class="{{ $thClasses }}">Assigned to</th>
                            <th scope="col" class="{{ $thClasses }} whitespace-nowrap">Downtime</th>
                            <th scope="col" class="{{ $thClasses }}">Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($workOrders as $workOrder)
                            <tr wire:key="history-{{ $workOrder->id }}" class="hover:bg-surface-muted">
                                <td class="border-b border-border px-[18px] py-3"><a href="{{ route('maintenance.work-orders.show', $workOrder) }}" class="font-mono text-xs font-semibold text-accent-ink hover:underline">{{ $workOrder->reference }}</a><span class="block max-w-xs truncate text-xs text-muted">{{ $workOrder->description }}</span></td>
                                <td class="whitespace-nowrap border-b border-border px-[18px] py-3 text-fg-soft">{{ __('maintenance.work_order_types.'.$workOrder->type) }}</td>
                                <td class="border-b border-border px-[18px] py-3"><span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-bold {{ \App\Models\WorkOrder::statusClasses($workOrder->status) }}">{{ __('maintenance.work_order_statuses.'.$workOrder->status) }}</span></td>
                                <td class="whitespace-nowrap border-b border-border px-[18px] py-3 text-fg-soft">{{ $workOrder->assignee?->name ?? 'Unassigned' }}</td>
                                <td class="whitespace-nowrap border-b border-border px-[18px] py-3 tabular-nums text-fg-soft">{{ $workOrder->downtime_minutes === null ? '—' : number_format($workOrder->downtime_minutes).' min' }}</td>
                                <td class="whitespace-nowrap border-b border-border px-[18px] py-3 text-fg-soft">{{ $workOrder->completed_at ? $settingsDate->formatDateTime($workOrder->completed_at) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($openForm)
        <x-modal-dialog id="equipment-detail-form" :title="match ($openForm) { 'status' => 'Change status', 'plan' => $editingPlanId ? 'Edit plan' : 'Add plan', default => 'Report a breakdown' }" close="closeForm" max-width="max-w-[560px]">
            <form id="equipment-detail-entry" wire:submit="submit" class="flex flex-col gap-3.5">
                @if ($openForm === 'status')
                    <fieldset>
                        <legend class="{{ $labelClasses }}">Status</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach (\App\Models\Equipment::STATUSES as $status)
                                <label class="flex items-center gap-2 rounded-md border border-border px-3 py-2 text-[13px]">
                                    <input type="radio" wire:model="form.status" value="{{ $status }}" class="size-4 text-accent-ink">
                                    <x-equipment-status-badge :status="$status" />
                                </label>
                            @endforeach
                        </div>
                        @error('form.status')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </fieldset>
                    <p class="text-xs text-muted">Out of service notifies production. Under maintenance or out of service shows a warning when runs are scheduled on {{ $equipment->productionLine?->name ?? 'its line' }}.</p>
                    <div>
                        <label for="status-note" class="{{ $labelClasses }}">Note (optional)</label>
                        <input id="status-note" type="text" wire:model="form.note" maxlength="200" class="{{ $inputClasses }}">
                    </div>
                @elseif ($openForm === 'plan')
                    <div>
                        <label for="plan-task" class="{{ $labelClasses }}">Task</label>
                        <input id="plan-task" type="text" wire:model="form.task" maxlength="160" placeholder="For example: Replace RO membrane" class="{{ $inputClasses }}">
                        @error('form.task')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid gap-3.5 sm:grid-cols-3">
                        <div>
                            <label for="plan-every" class="{{ $labelClasses }}">Every (days)</label>
                            <input id="plan-every" type="number" min="1" max="3650" wire:model="form.every_days" class="{{ $inputClasses }}">
                            @error('form.every_days')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="plan-first" class="{{ $labelClasses }}">First due</label>
                            <input id="plan-first" type="date" wire:model="form.first_due_on" class="{{ $inputClasses }}">
                            @error('form.first_due_on')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="plan-last" class="{{ $labelClasses }}">Last done</label>
                            <input id="plan-last" type="date" wire:model="form.last_done_on" class="{{ $inputClasses }}">
                            @error('form.last_done_on')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div>
                        <label for="plan-role" class="{{ $labelClasses }}">Done by</label>
                        <select id="plan-role" wire:model="form.assigned_role_id" class="{{ $inputClasses }}">
                            <option value="">Any maintenance user</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="plan-checklist" class="{{ $labelClasses }}">Checklist (one step per line)</label>
                        <textarea id="plan-checklist" rows="4" wire:model="form.checklist" class="{{ $inputClasses }}"></textarea>
                        @error('form.checklist')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>
                    <label class="flex items-center gap-2 text-[13px] text-fg-soft"><input type="checkbox" wire:model="form.active" class="size-4 rounded border-border-strong text-accent-ink"> Active</label>
                @else
                    <div class="grid gap-3.5 sm:grid-cols-2">
                        <div>
                            <label for="breakdown-type" class="{{ $labelClasses }}">Type</label>
                            <select id="breakdown-type" wire:model="form.type" class="{{ $inputClasses }}">
                                @foreach (\App\Models\WorkOrder::TYPES as $type)
                                    <option value="{{ $type }}">{{ __('maintenance.work_order_types.'.$type) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="breakdown-priority" class="{{ $labelClasses }}">Priority</label>
                            <select id="breakdown-priority" wire:model="form.priority" class="{{ $inputClasses }}">
                                @foreach (\App\Models\WorkOrder::PRIORITIES as $priority)
                                    <option value="{{ $priority }}">{{ __('maintenance.priorities.'.$priority) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="breakdown-assignee" class="{{ $labelClasses }}">Assign to</label>
                            <select id="breakdown-assignee" wire:model="form.assigned_to" class="{{ $inputClasses }}">
                                <option value="">Nobody yet</option>
                                @foreach ($assignees as $assignee)
                                    <option value="{{ $assignee->id }}">{{ $assignee->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="breakdown-status" class="{{ $labelClasses }}">Also set the equipment to</label>
                            <select id="breakdown-status" wire:model="form.equipment_status" class="{{ $inputClasses }}">
                                <option value="">Leave as {{ $equipment->statusLabel() }}</option>
                                @foreach (\App\Models\Equipment::STATUSES as $status)
                                    <option value="{{ $status }}">{{ __('maintenance.equipment_statuses.'.$status) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="breakdown-description" class="{{ $labelClasses }}">What is wrong?</label>
                        <textarea id="breakdown-description" rows="4" wire:model="form.description" class="{{ $inputClasses }}"></textarea>
                        @error('form.description')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>
                @endif
            </form>
            <x-slot:footer>
                <button type="button" wire:click="closeForm" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Cancel</button>
                <button type="submit" form="equipment-detail-entry" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">{{ $openForm === 'breakdown' ? 'Create work order' : 'Save' }}</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif
</div>
