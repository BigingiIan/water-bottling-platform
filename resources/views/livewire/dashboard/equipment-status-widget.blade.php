<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Equipment not operational" icon="cog-6-tooth" :href="route('maintenance.equipment.index')" link-label="Equipment">
        @if ($equipment->isEmpty())
            <p class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-muted">All equipment is operational.</p>
        @else
            <p class="text-2xl font-extrabold tabular-nums text-fg">{{ \App\Support\Plural::count($equipment->count(), 'machine') }}</p>
            <ul class="mt-2 space-y-1.5">
                @foreach ($equipment->take(3) as $machine)
                    <li wire:key="dashboard-equipment-{{ $machine->id }}" class="flex items-center justify-between gap-2 text-xs text-fg-soft">
                        <a href="{{ route('maintenance.equipment.show', $machine) }}" class="truncate hover:text-accent-ink"><b class="font-semibold text-fg">{{ $machine->code }}</b> · {{ $machine->productionLine?->name ?? $machine->name }}</a>
                        <x-equipment-status-badge :status="$machine->status" class="shrink-0" />
                    </li>
                @endforeach
            </ul>
            @if ($equipment->count() > 3)
                <p class="mt-1.5 text-xs text-muted">and {{ $equipment->count() - 3 }} more</p>
            @endif
        @endif
    </x-dashboard-card>
</div>
