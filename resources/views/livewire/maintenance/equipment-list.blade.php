@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $thClasses = 'border-b border-border bg-surface-muted px-[18px] py-2.5 text-[11px] font-bold uppercase tracking-[0.4px] text-muted';
    $filterClasses = 'rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft';
    $inputClasses = 'w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-[13px] text-fg focus:border-accent focus:outline-none focus:ring-2 focus:ring-focus/20';
    $labelClasses = 'mb-1 block text-xs font-semibold text-fg-soft';
@endphp

<div class="flex flex-col gap-6">
    <x-page-header help="maintenance" heading-id="equipment-heading" :title="__('maintenance.pages.equipment')" :subtitle="__('maintenance.pages.equipment_subtitle')">
        <x-slot:actions>
            <a href="{{ route('maintenance.work-orders.index') }}" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">
                <x-heroicon-o-clipboard-document-check class="size-4" aria-hidden="true" />
                {{ __('maintenance.pages.work_orders') }}
            </a>
            @if ($canManage)
                <button type="button" wire:click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">
                    <x-heroicon-o-plus class="size-4" aria-hidden="true" />
                    Add equipment
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <section aria-labelledby="equipment-heading" class="overflow-hidden rounded-lg border border-border bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-border bg-surface-muted px-[18px] py-3">
            <label class="relative w-full sm:w-[220px]">
                <span class="sr-only">Search equipment</span>
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Code or name" class="w-full rounded-md border border-border-strong bg-surface py-1.5 pl-8 pr-2.5 text-xs text-fg-soft placeholder:text-muted">
            </label>
            <select wire:model.live="lineFilter" aria-label="Filter by line" class="{{ $filterClasses }}">
                <option value="">All lines</option>
                @foreach ($lines as $line)
                    <option value="{{ $line->id }}">{{ $line->name }}</option>
                @endforeach
                <option value="none">Not on a line</option>
            </select>
            <select wire:model.live="statusFilter" aria-label="Filter by status" class="{{ $filterClasses }}">
                <option value="">All statuses</option>
                @foreach (\App\Models\Equipment::STATUSES as $status)
                    <option value="{{ $status }}">{{ __('maintenance.equipment_statuses.'.$status) }}</option>
                @endforeach
            </select>
            <span class="ml-auto text-xs text-muted">{{ \App\Support\Plural::count($equipment->count(), 'machine') }}</span>
        </div>

        @if ($equipment->isEmpty())
            <div class="flex flex-col items-center px-[18px] py-10 text-center">
                <x-heroicon-o-wrench-screwdriver class="size-8 text-subtle" aria-hidden="true" />
                <p class="mt-2 text-sm text-fg-soft">{{ $hasFilters ? __('help.empty.filtered') : __('maintenance.empty.equipment') }}</p>
                @if ($canManage && ! $hasFilters)
                    <button type="button" wire:click="openCreate" class="mt-3 inline-flex items-center gap-1.5 rounded-md bg-accent px-3 py-1.5 text-xs font-semibold text-on-accent hover:bg-accent-hover"><x-heroicon-o-plus class="size-3.5" aria-hidden="true" />Add equipment</button>
                @endif
            </div>
        @else
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[820px] border-separate border-spacing-0 text-left text-[13px]">
                    <caption class="sr-only">Equipment with its line, status, next preventive task and recent downtime</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $thClasses }}">Equipment</th>
                            <th scope="col" class="{{ $thClasses }}">Line</th>
                            <th scope="col" class="{{ $thClasses }}">Status</th>
                            <th scope="col" class="{{ $thClasses }}">Next due task</th>
                            <th scope="col" class="{{ $thClasses }} whitespace-nowrap">Downtime (30 days)</th>
                            <th scope="col" class="{{ $thClasses }}"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($equipment as $machine)
                            @php
                                $nextDue = $machine->next_due_on ? \Carbon\CarbonImmutable::parse($machine->next_due_on) : null;
                                $isOverdue = $nextDue !== null && $nextDue->lessThan(today());
                            @endphp
                            <tr wire:key="equipment-{{ $machine->id }}" class="group">
                                <td class="border-b border-border px-[18px] py-3 group-hover:bg-surface-muted">
                                    <a href="{{ route('maintenance.equipment.show', $machine) }}" class="block">
                                        <span class="block font-semibold text-fg group-hover:text-accent-ink">{{ $machine->name }}</span>
                                        <span class="block text-[11.5px] text-muted"><span class="font-mono">{{ $machine->code }}</span> · {{ __('maintenance.equipment_types.'.$machine->type) }}</span>
                                    </a>
                                </td>
                                <td class="whitespace-nowrap border-b border-border px-[18px] py-3 text-fg-soft group-hover:bg-surface-muted">{{ $machine->productionLine?->name ?? '—' }}</td>
                                <td class="border-b border-border px-[18px] py-3 group-hover:bg-surface-muted"><x-equipment-status-badge :status="$machine->status" /></td>
                                <td class="border-b border-border px-[18px] py-3 group-hover:bg-surface-muted">
                                    @if ($nextDue)
                                        <span class="block text-fg">{{ $machine->next_task }}</span>
                                        <span @class(['block whitespace-nowrap text-[11.5px]', 'font-semibold text-danger-ink' => $isOverdue, 'text-muted' => ! $isOverdue])>
                                            @if ($isOverdue)<span class="sr-only">Overdue, </span>@endif{{ $isOverdue ? 'Overdue since' : 'Due' }} {{ $settingsDate->formatDate($nextDue) }}
                                        </span>
                                    @else
                                        <span class="text-muted">No active plan</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap border-b border-border px-[18px] py-3 tabular-nums text-fg-soft group-hover:bg-surface-muted">{{ number_format($downtime[$machine->id] ?? 0) }} min</td>
                                <td class="whitespace-nowrap border-b border-border px-[18px] py-3 text-right group-hover:bg-surface-muted">
                                    @if ($canManage)
                                        <button type="button" wire:click="openEdit({{ $machine->id }})" aria-label="Edit {{ $machine->name }}" class="text-xs font-semibold text-accent-ink hover:text-accent-ink">Edit</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($formOpen)
        <x-modal-dialog id="equipment-form" :title="$editingId ? 'Edit equipment' : 'Add equipment'" close="closeForm" max-width="max-w-[560px]">
            <form id="equipment-entry-form" wire:submit="save" class="grid gap-3.5 sm:grid-cols-2">
                @foreach ([
                    ['code', 'Code', 'text'], ['name', 'Name', 'text'], ['location', 'Location', 'text'], ['manufacturer', 'Manufacturer', 'text'],
                    ['model', 'Model', 'text'], ['serial_number', 'Serial number', 'text'], ['installed_on', 'Installed on', 'date'],
                ] as [$field, $label, $type])
                    <div>
                        <label for="equipment-{{ $field }}" class="{{ $labelClasses }}">{{ $label }}</label>
                        <input id="equipment-{{ $field }}" type="{{ $type }}" wire:model="form.{{ $field }}" class="{{ $inputClasses }}">
                        @error('form.'.$field)<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                    </div>
                @endforeach
                <div>
                    <label for="equipment-type" class="{{ $labelClasses }}">Type</label>
                    <select id="equipment-type" wire:model="form.type" class="{{ $inputClasses }}">
                        @foreach (\App\Models\Equipment::TYPES as $type)
                            <option value="{{ $type }}">{{ __('maintenance.equipment_types.'.$type) }}</option>
                        @endforeach
                    </select>
                    @error('form.type')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="equipment-line" class="{{ $labelClasses }}">Production line</label>
                    <select id="equipment-line" wire:model="form.production_line_id" class="{{ $inputClasses }}">
                        <option value="">Not on a line</option>
                        @foreach ($lines as $line)
                            <option value="{{ $line->id }}">{{ $line->name }}</option>
                        @endforeach
                    </select>
                    @error('form.production_line_id')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="equipment-notes" class="{{ $labelClasses }}">Notes</label>
                    <textarea id="equipment-notes" rows="2" wire:model="form.notes" class="{{ $inputClasses }}"></textarea>
                    @error('form.notes')<p role="alert" class="mt-1 text-xs text-danger-ink">{{ $message }}</p>@enderror
                </div>
            </form>
            <x-slot:footer>
                <button type="button" wire:click="closeForm" class="rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">Cancel</button>
                <button type="submit" form="equipment-entry-form" class="rounded-md bg-accent px-3.5 py-2 text-[13px] font-semibold text-on-accent hover:bg-accent-hover">Save</button>
            </x-slot:footer>
        </x-modal-dialog>
    @endif
</div>
