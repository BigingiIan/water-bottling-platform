@php
    $settingsDate = app(\App\Support\SettingsDate::class);
@endphp

<div class="flex flex-col gap-6">
    <x-page-header help="maintenance" heading-id="maintenance-calendar-heading" :title="__('maintenance.pages.calendar')" :subtitle="__('maintenance.pages.calendar_subtitle')" />

    @if ($overdue->isNotEmpty())
        <section aria-labelledby="overdue-heading" class="overflow-hidden rounded-lg border border-danger/35 bg-surface">
            <h2 id="overdue-heading" class="flex items-center gap-2 border-b border-danger/20 bg-danger-soft px-[18px] py-3 text-sm font-bold text-danger-ink">
                <x-heroicon-o-exclamation-triangle class="size-5" aria-hidden="true" />
                Overdue <span class="font-normal">· {{ \App\Support\Plural::count($overdue->count(), 'task') }}</span>
            </h2>
            <ul class="divide-y divide-border">
                @foreach ($overdue as $plan)
                    <li wire:key="overdue-{{ $plan->id }}" class="flex flex-wrap items-center justify-between gap-3 px-[18px] py-3 text-[13px]">
                        <span class="min-w-0">
                            <a href="{{ route('maintenance.equipment.show', $plan->equipment_id) }}" class="font-semibold text-fg hover:text-accent-ink">{{ $plan->task }}</a>
                            <span class="block text-xs text-muted">{{ $plan->equipment?->code }} · {{ $plan->equipment?->name }} · {{ $plan->assignedRole?->name ?? 'Any maintenance user' }}</span>
                        </span>
                        <span class="whitespace-nowrap text-xs font-semibold text-danger-ink">Due {{ $settingsDate->formatDate($plan->next_due_on) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($weeks === [])
        <p class="rounded-lg border border-border bg-surface px-[18px] py-8 text-center text-sm text-muted">{{ __('maintenance.empty.calendar') }}</p>
    @else
        @foreach ($weeks as $weekStart => $occurrences)
            @php
                $monday = \Carbon\CarbonImmutable::parse($weekStart);
            @endphp
            <section wire:key="week-{{ $weekStart }}" aria-labelledby="week-{{ $weekStart }}" class="overflow-hidden rounded-lg border border-border bg-surface">
                <h2 id="week-{{ $weekStart }}" class="border-b border-border px-[18px] py-3 text-sm font-bold text-fg">
                    Week of {{ $settingsDate->formatDate($monday) }} <span class="font-normal text-muted">· {{ \App\Support\Plural::count(count($occurrences), 'task') }}</span>
                </h2>
                <ul class="divide-y divide-border">
                    @foreach ($occurrences as $occurrence)
                        <li wire:key="occurrence-{{ $occurrence['plan']->id }}-{{ $occurrence['date']->toDateString() }}" class="flex flex-wrap items-center gap-3 px-[18px] py-2.5 text-[13px]">
                            <span class="w-24 shrink-0 whitespace-nowrap font-semibold text-fg">{{ $occurrence['date']->format('D j M') }}</span>
                            <span class="min-w-0 flex-1">
                                <a href="{{ route('maintenance.equipment.show', $occurrence['plan']->equipment_id) }}" class="font-semibold text-fg hover:text-accent-ink">{{ $occurrence['plan']->task }}</a>
                                <span class="block text-xs text-muted">{{ $occurrence['plan']->equipment?->code }} · {{ $occurrence['plan']->equipment?->name }} · every {{ \App\Support\Plural::count($occurrence['plan']->every_days, 'day') }}</span>
                            </span>
                            @if ($occurrence['projected'])
                                <span class="whitespace-nowrap rounded-full bg-surface-strong px-2 py-0.5 text-[10.5px] font-bold text-muted" title="Projected from the interval; moves when the previous task is done">Repeat</span>
                            @else
                                <span class="whitespace-nowrap rounded-full bg-accent-soft px-2 py-0.5 text-[10.5px] font-bold text-accent-ink">Next due</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif
</div>
