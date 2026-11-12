@php
    $filterClasses = 'rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs font-medium text-fg-soft';
    $priorityClasses = ['urgent' => 'bg-danger-soft text-danger-ink', 'normal' => 'bg-accent-soft text-accent-ink', 'low' => 'bg-surface-strong text-fg-soft'];
@endphp

<div class="flex flex-col gap-6">
    <x-page-header help="maintenance" heading-id="work-orders-heading" :title="__('maintenance.pages.work_orders')" :subtitle="__('maintenance.pages.work_orders_subtitle')">
        <x-slot:actions>
            <a href="{{ route('maintenance.calendar') }}" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">
                <x-heroicon-o-calendar-days class="size-4" aria-hidden="true" />
                {{ __('maintenance.pages.calendar') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-surface-muted px-[18px] py-3">
        <select wire:model.live="equipmentFilter" aria-label="Filter by equipment" class="{{ $filterClasses }}">
            <option value="">All equipment</option>
            @foreach ($equipment as $machine)
                <option value="{{ $machine->id }}">{{ $machine->code }} · {{ $machine->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="lineFilter" aria-label="Filter by line" class="{{ $filterClasses }}">
            <option value="">All lines</option>
            @foreach ($lines as $line)
                <option value="{{ $line->id }}">{{ $line->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="typeFilter" aria-label="Filter by type" class="{{ $filterClasses }}">
            <option value="">All types</option>
            @foreach (\App\Models\WorkOrder::TYPES as $type)
                <option value="{{ $type }}">{{ __('maintenance.work_order_types.'.$type) }}</option>
            @endforeach
        </select>
        <select wire:model.live="priorityFilter" aria-label="Filter by priority" class="{{ $filterClasses }}">
            <option value="">All priorities</option>
            @foreach (\App\Models\WorkOrder::PRIORITIES as $priority)
                <option value="{{ $priority }}">{{ __('maintenance.priorities.'.$priority) }}</option>
            @endforeach
        </select>
        <label class="inline-flex items-center gap-1.5 text-xs font-medium text-fg-soft"><input type="checkbox" wire:model.live="mineOnly" class="size-4 rounded border-border-strong text-accent-ink"> Assigned to me</label>
        @if ($hasFilters)
            <button type="button" wire:click="clearFilters" class="inline-flex items-center gap-1 text-xs font-semibold text-accent-ink hover:text-accent-ink"><x-heroicon-o-x-mark class="size-3.5" aria-hidden="true" />Clear filters</button>
        @endif
    </div>

    <div class="grid gap-[18px] lg:grid-cols-3">
        @foreach (['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done (last '.\App\Livewire\Maintenance\WorkOrdersBoard::DONE_DAYS.' days)'] as $status => $label)
            <section aria-labelledby="column-{{ $status }}" class="flex min-w-0 flex-col rounded-lg border border-border bg-surface">
                <h2 id="column-{{ $status }}" class="flex items-center justify-between border-b border-border px-4 py-3 text-[13px] font-bold text-fg">
                    {{ $label }}
                    <span class="rounded-full bg-surface-strong px-2 py-0.5 text-[11px] font-bold text-fg-soft">{{ $columns[$status]->count() }}</span>
                </h2>
                @if ($columns[$status]->isEmpty())
                    <p class="px-4 py-6 text-center text-xs text-muted">{{ $hasFilters ? __('maintenance.empty.work_orders') : 'Nothing here.' }}</p>
                @else
                    <ul class="flex flex-col gap-2 p-3">
                        @foreach ($columns[$status] as $workOrder)
                            <li wire:key="board-{{ $workOrder->id }}">
                                <a href="{{ route('maintenance.work-orders.show', $workOrder) }}" class="block rounded-md border border-border px-3 py-2.5 hover:border-accent/35 hover:bg-accent-soft/40">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="font-mono text-xs font-semibold text-accent-ink">{{ $workOrder->reference }}</span>
                                        <span class="whitespace-nowrap rounded px-2 py-0.5 text-[10.5px] font-bold {{ $priorityClasses[$workOrder->priority] }}">{{ __('maintenance.priorities.'.$workOrder->priority) }}</span>
                                    </span>
                                    <span class="mt-1 block truncate text-[13px] font-semibold text-fg">{{ $workOrder->equipment?->code }} · {{ $workOrder->equipment?->name }}</span>
                                    <span class="mt-0.5 block truncate text-xs text-fg-soft">{{ $workOrder->description }}</span>
                                    <span class="mt-1.5 block text-[11px] text-muted">{{ __('maintenance.work_order_types.'.$workOrder->type) }} · {{ $workOrder->assignee?->name ?? 'Unassigned' }}@if ($workOrder->downtime_minutes !== null) · {{ number_format($workOrder->downtime_minutes) }} min down @endif</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>
</div>
