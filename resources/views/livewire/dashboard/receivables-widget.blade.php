<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Receivables" icon="banknotes" :href="route('invoices.index')" link-label="Invoices">
        @if ($receivables['openCount'] === 0)
            <p class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-gray-500">No issued invoices are waiting for payment.</p>
        @else
            <p class="text-2xl font-extrabold tabular-nums text-gray-900">{{ \App\Services\Orders\Money::format($receivables['outstanding']) }}</p>
            <p class="mt-1 text-xs text-gray-500">Outstanding across {{ \App\Support\Plural::count($receivables['openCount'], 'invoice') }}</p>
            @if ($receivables['overdueCount'] > 0)
                <p class="mt-3 inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-red-50 px-2.5 py-1 text-[11px] font-bold text-[#b91c1c]">
                    <x-heroicon-o-exclamation-triangle class="size-3.5" aria-hidden="true" />
                    {{ \App\Services\Orders\Money::format($receivables['overdue']) }} overdue · {{ \App\Support\Plural::count($receivables['overdueCount'], 'invoice') }}
                </p>
            @else
                <p class="mt-3 text-xs font-semibold text-[#15803d]">Nothing is overdue.</p>
            @endif
        @endif
    </x-dashboard-card>
</div>
