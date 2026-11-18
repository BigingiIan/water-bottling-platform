<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Orders today" icon="truck" :href="route('orders.index')" link-label="Orders">
        @if ($orders['toDispatch'] === 0 && $orders['awaitingDelivery'] === 0)
            <p class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-muted">No orders are due for dispatch or out for delivery.</p>
        @else
            <dl class="grid grid-cols-2 gap-3">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.4px] text-muted">To dispatch</dt>
                    <dd class="mt-0.5 text-2xl font-extrabold tabular-nums text-fg">{{ $orders['toDispatch'] }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.4px] text-muted">Awaiting delivery</dt>
                    <dd class="mt-0.5 text-2xl font-extrabold tabular-nums text-fg">{{ $orders['awaitingDelivery'] }}</dd>
                </div>
            </dl>
            @if ($orders['dueToDispatch']->isNotEmpty())
                <ul class="mt-3 space-y-1.5 text-xs text-fg-soft">
                    @foreach ($orders['dueToDispatch'] as $order)
                        <li wire:key="dashboard-order-{{ $order->id }}" class="truncate">
                            <a href="{{ route('orders.show', $order) }}" class="font-semibold text-fg hover:text-accent-ink hover:underline">{{ $order->order_number }}</a>
                            · {{ $order->customer?->name ?? 'Customer' }}
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </x-dashboard-card>
</div>
