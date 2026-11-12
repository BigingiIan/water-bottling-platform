<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Stock alerts" icon="archive-box" :href="route('inventory.overview')" link-label="Stock">
        @if ($stockAlerts->isEmpty())
            <p class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-muted">All products are above their reorder thresholds.</p>
        @else
            <p class="text-2xl font-extrabold tabular-nums text-fg">{{ \App\Support\Plural::count($stockAlerts->count(), 'product') }}</p>
            <ul class="mt-2 space-y-1.5">
                @foreach ($stockAlerts->take(3) as $alert)
                    <li wire:key="dashboard-stock-{{ $alert['product']->id }}" class="flex items-center justify-between gap-2 text-xs text-fg-soft">
                        <span class="truncate"><b class="font-semibold text-fg">{{ $alert['product']->unit_type }}</b> · {{ \App\Support\Plural::count($alert['units'], 'unit') }}</span>
                        <x-stock-status-badge :status="$alert['status']" />
                    </li>
                @endforeach
            </ul>
            @if ($stockAlerts->count() > 3)
                <p class="mt-1.5 text-xs text-muted">and {{ $stockAlerts->count() - 3 }} more</p>
            @endif
        @endif
    </x-dashboard-card>
</div>
