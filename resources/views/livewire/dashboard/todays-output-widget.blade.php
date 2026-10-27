<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Today's output" icon="cube" :href="route('production.schedule')" link-label="Schedule">
        @if ($production['scheduledRuns'] === 0)
            <div class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-gray-500">
                <p>No runs scheduled today.</p>
                @if ($canCreateSchedule)
                    <a href="{{ route('production.schedule') }}" class="text-xs font-semibold text-blue-700 hover:text-blue-900">Create a schedule</a>
                @endif
            </div>
        @else
            <p class="text-2xl font-extrabold tabular-nums text-gray-900">
                {{ number_format($production['actual']) }}
                <span class="text-sm font-semibold text-gray-400">/ {{ \App\Support\Plural::count($production['target'], 'unit') }}</span>
            </p>
            <div class="mt-3 h-1.5 overflow-hidden rounded bg-gray-100" role="progressbar" aria-valuenow="{{ min(100, $production['percent']) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Output against today's target">
                <div class="h-full rounded bg-blue-500" style="width: {{ min(100, $production['percent']) }}%"></div>
            </div>
            <p class="mt-2 text-xs text-gray-500">{{ $production['percent'] }}% of target across {{ \App\Support\Plural::count($production['scheduledRuns'], 'run') }}</p>
        @endif
    </x-dashboard-card>
</div>
