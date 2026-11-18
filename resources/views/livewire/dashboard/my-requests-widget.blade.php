<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="My requests" icon="inbox-arrow-down" :href="route('requests.index')" link-label="All">
        @if ($requests->isEmpty())
            <p class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-muted">No open requests are addressed to you.</p>
        @else
            <ul class="-mx-[18px] -my-4 divide-y divide-border">
                @foreach ($requests as $request)
                    <li wire:key="dashboard-request-{{ $request->getKey() }}">
                        <a href="{{ $request->url() }}" class="flex items-start justify-between gap-2 px-[18px] py-3 hover:bg-surface-muted">
                            <span class="min-w-0">
                                <span class="block truncate text-[13px] font-medium text-fg">{{ $request->subject }}</span>
                                <span class="mt-0.5 block text-[11px] text-muted">{{ $request->reference }} · {{ $request->created_at?->diffForHumans() }}</span>
                            </span>
                            <x-request-status-badge :status="$request->status" class="shrink-0" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-dashboard-card>
</div>
