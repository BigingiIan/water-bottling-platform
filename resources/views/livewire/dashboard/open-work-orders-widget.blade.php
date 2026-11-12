<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Open work orders" icon="clipboard-document-check" :href="route('maintenance.work-orders.index')" link-label="Board">
        @if ($workOrders['open'] + $workOrders['inProgress'] === 0)
            <p class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-muted">No work orders are open.</p>
        @else
            <dl class="grid grid-cols-2 gap-3">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.4px] text-muted">Open</dt>
                    <dd class="mt-0.5 text-2xl font-extrabold tabular-nums text-fg">{{ $workOrders['open'] }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.4px] text-muted">In progress</dt>
                    <dd class="mt-0.5 text-2xl font-extrabold tabular-nums text-fg">{{ $workOrders['inProgress'] }}</dd>
                </div>
            </dl>
            @if ($workOrders['urgent'] > 0)
                <p class="mt-2 inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-danger-soft px-2.5 py-0.5 text-[11px] font-bold text-danger-ink">{{ $workOrders['urgent'] }} urgent</p>
            @endif
            <ul class="mt-2 space-y-1.5 text-xs text-fg-soft">
                @foreach ($workOrders['latest'] as $workOrder)
                    <li wire:key="dashboard-work-order-{{ $workOrder->id }}" class="truncate">
                        <a href="{{ route('maintenance.work-orders.show', $workOrder) }}" class="font-semibold text-fg hover:text-accent-ink hover:underline">{{ $workOrder->reference }}</a> · {{ $workOrder->equipment?->code }}
                    </li>
                @endforeach
            </ul>
        @endif
    </x-dashboard-card>
</div>
