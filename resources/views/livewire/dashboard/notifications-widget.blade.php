@php
    $severityDotClasses = ['info' => 'bg-accent', 'warning' => 'bg-warning', 'critical' => 'bg-danger'];
@endphp
<div wire:poll.60s class="h-full">
    <x-dashboard-card class="h-full" title="Latest notifications" icon="bell" :href="route('notifications.index')" link-label="All">
        @if ($notifications->isEmpty())
            <p class="flex flex-1 flex-col items-center justify-center gap-1.5 py-4 text-center text-sm text-muted">You're all caught up. New alerts will appear here.</p>
        @else
            <ul class="-mx-[18px] -my-4 divide-y divide-border">
                @foreach ($notifications as $notification)
                    @php
                        $severity = $notification->data['severity'] ?? 'info';
                    @endphp
                    <li wire:key="dashboard-notification-{{ $notification->id }}">
                        <a href="{{ \App\Livewire\NotificationBell::safeUrl($notification->data['url'] ?? null) }}" @class(['flex items-start gap-3 px-[18px] py-3 hover:bg-surface-muted', 'bg-accent-soft/60' => $notification->read_at === null])>
                            <span aria-hidden="true" @class(['mt-1.5 size-2 shrink-0 rounded-full', $severityDotClasses[$severity] ?? 'bg-accent'])></span>
                            <span class="min-w-0">
                                <span class="block truncate text-[13px] font-medium text-fg">
                                    @if ($notification->read_at === null)<span class="sr-only">Unread: </span>@endif{{ $notification->data['title'] ?? 'Notification' }}
                                </span>
                                <span class="mt-0.5 block text-[11px] text-muted">{{ $notification->created_at?->diffForHumans() }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-dashboard-card>
</div>
