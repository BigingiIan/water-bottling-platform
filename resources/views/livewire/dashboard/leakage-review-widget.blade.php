<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Leakage review" icon="beaker" :href="route('inventory.overview', ['tab' => 'leakage'])" link-label="Leakage">
        @if ($leakageAttentionCount === 0)
            <p class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-muted">No open leakage reports need review.</p>
        @else
            <p class="text-2xl font-extrabold tabular-nums text-fg">{{ \App\Support\Plural::count($leakageAttentionCount, 'report') }}</p>
            <p class="mt-2 text-xs text-muted">Open and flagged Review or Investigate, from manual entries, batch checks, and stock counts.</p>
        @endif
    </x-dashboard-card>
</div>
