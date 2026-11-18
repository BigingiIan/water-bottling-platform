@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $plans = $due['overdue']->concat($due['dueThisWeek']);
@endphp
<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Maintenance due" icon="wrench-screwdriver" :href="route('maintenance.calendar')" link-label="Calendar">
        @if ($plans->isEmpty())
            <p class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-muted">No preventive tasks are overdue or due this week.</p>
        @else
            <dl class="grid grid-cols-2 gap-3">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.4px] text-muted">Overdue</dt>
                    <dd @class(['mt-0.5 text-2xl font-extrabold tabular-nums', 'text-danger-ink' => $due['overdue']->isNotEmpty(), 'text-fg' => $due['overdue']->isEmpty()])>{{ $due['overdue']->count() }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.4px] text-muted">This week</dt>
                    <dd class="mt-0.5 text-2xl font-extrabold tabular-nums text-fg">{{ $due['dueThisWeek']->count() }}</dd>
                </div>
            </dl>
            <ul class="mt-3 space-y-1.5 text-xs text-fg-soft">
                @foreach ($plans->take(3) as $plan)
                    <li wire:key="dashboard-maintenance-{{ $plan->id }}" class="flex items-center justify-between gap-2">
                        <span class="truncate"><b class="font-semibold text-fg">{{ $plan->equipment?->code }}</b> · {{ $plan->task }}</span>
                        <span @class(['shrink-0 whitespace-nowrap font-semibold', 'text-danger-ink' => $plan->isOverdue(), 'text-muted' => ! $plan->isOverdue()])>{{ $plan->next_due_on->format('j M') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-dashboard-card>
</div>
