<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Stock alerts" icon="archive-box" :href="route('inventory.overview')" link-label="Stock">
        @if ($stockAlerts->isEmpty())
            <p class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-gray-500">All products are above their reorder thresholds.</p>
        @else
            <p class="text-2xl font-extrabold tabular-nums text-gray-900">{{ \App\Support\Plural::count($stockAlerts->count(), 'product') }}</p>
            <ul class="mt-2 space-y-1.5">
                @foreach ($stockAlerts->take(3) as $alert)
                    <li wire:key="dashboard-stock-{{ $alert['product']->id }}" class="flex items-center justify-between gap-2 text-xs text-gray-600">
                        <span class="truncate"><b class="font-semibold text-gray-800">{{ $alert['product']->unit_type }}</b> · {{ \App\Support\Plural::count($alert['units'], 'unit') }}</span>
                        <x-stock-status-badge :status="$alert['status']" />
                    </li>
                @endforeach
            </ul>
            @if ($stockAlerts->count() > 3)
                <p class="mt-1.5 text-xs text-gray-500">and {{ $stockAlerts->count() - 3 }} more</p>
            @endif
        @endif
    </x-dashboard-card>
</div>
