<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Today's output" icon="cube" :href="route('production.schedule')" link-label="Schedule">
        @if ($production['scheduledRuns'] === 0)
            <div class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-muted">
                <p>No runs scheduled today.</p>
                @if ($canCreateSchedule)
                    <a href="{{ route('production.schedule') }}" class="text-xs font-semibold text-accent-ink hover:text-accent-ink">Create a schedule</a>
                @endif
            </div>
        @else
            <p class="text-2xl font-extrabold tabular-nums text-fg">
                {{ number_format($production['actual']) }}
                <span class="text-sm font-semibold text-muted">/ {{ \App\Support\Plural::count($production['target'], 'unit') }}</span>
            </p>
            <div class="mt-3 h-1.5 overflow-hidden rounded bg-surface-strong" role="progressbar" aria-valuenow="{{ min(100, $production['percent']) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Output against today's target">
                <div class="h-full rounded bg-accent" style="width: {{ min(100, $production['percent']) }}%"></div>
            </div>
            <p class="mt-2 text-xs text-muted">{{ $production['percent'] }}% of target across {{ \App\Support\Plural::count($production['scheduledRuns'], 'run') }}</p>
        @endif
    </x-dashboard-card>
</div>
