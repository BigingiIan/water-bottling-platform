<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Open runs" icon="play-circle" :href="route('production.runs')" link-label="Runs">
        @if ($openRuns->isEmpty())
            <p class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-gray-500">No open runs today. Every scheduled run is complete.</p>
        @else
            <p class="text-2xl font-extrabold tabular-nums text-gray-900">{{ $openRuns->count() }}</p>
            <ul class="mt-2 space-y-1.5 text-xs text-gray-600">
                @foreach ($openRuns->take(3) as $run)
                    <li wire:key="dashboard-run-{{ $run->id }}" class="flex items-center justify-between gap-2">
                        <span class="truncate"><b class="font-semibold text-gray-800">{{ $run->product_sku }}</b> · {{ $run->productionLine?->name ?? 'Line' }} · {{ mb_substr((string) $run->scheduled_time, 0, 5) }}</span>
                        <span @class(['shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-bold', 'bg-blue-50 text-[#1d4ed8]' => $run->status === 'active', 'bg-gray-100 text-gray-600' => $run->status !== 'active'])>{{ ucfirst($run->status) }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($openRuns->count() > 3)
                <p class="mt-1.5 text-xs text-gray-500">and {{ $openRuns->count() - 3 }} more</p>
            @endif
        @endif
    </x-dashboard-card>
</div>
