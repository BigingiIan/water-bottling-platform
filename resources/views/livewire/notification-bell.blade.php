@php
    $severityDotClasses = [
        'info' => 'bg-accent',
        'warning' => 'bg-warning',
        'critical' => 'bg-danger',
    ];
@endphp

<div
    class="relative"
    x-on:click.outside="if ($wire.open) $wire.$set('open', false)"
    x-on:keydown.escape.window="if ($wire.open) { $wire.$set('open', false); $refs.bellButton.focus() }"
>
    @unless ($open)
        <div wire:poll.60s class="hidden" aria-hidden="true"></div>
    @endunless

    <button
        x-ref="bellButton"
        type="button"
        aria-label="Notifications, {{ $unreadCount }} unread"
        aria-haspopup="true"
        aria-controls="notification-dropdown"
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        x-bind:aria-expanded="$wire.open ? 'true' : 'false'"
        x-on:click="$wire.$toggle('open')"
        class="relative flex size-[34px] items-center justify-center rounded-md bg-surface-strong text-fg-soft transition-colors hover:bg-border hover:text-fg"
    >
        <x-heroicon-o-bell class="size-5" aria-hidden="true" />
        @if ($unreadCount > 0)
            <span aria-hidden="true" class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold leading-none text-on-danger">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <section
        id="notification-dropdown"
        aria-label="Notifications"
        x-show="$wire.open"
        @unless ($open) style="display: none" @endunless
        class="absolute right-0 z-30 mt-2 w-[360px] max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-border bg-surface shadow-lg"
    >
        <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <h2 class="text-[13px] font-bold text-fg">Notifications</h2>
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllAsRead" class="text-xs font-semibold text-accent-ink hover:text-accent-ink hover:underline">Mark all as read</button>
            @endif
        </div>

        <ul class="max-h-[420px] divide-y divide-border overflow-y-auto">
            @forelse ($notifications as $notification)
                @php
                    $isUnread = $notification->read_at === null;
                    $severity = $notification->data['severity'] ?? 'info';
                @endphp
                <li wire:key="bell-notification-{{ $notification->id }}">
                    <button
                        type="button"
                        wire:click="openNotification('{{ $notification->id }}')"
                        @class([
                            'flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-surface-muted focus:bg-surface-muted focus:outline-none',
                            'bg-accent-soft/60' => $isUnread,
                        ])
                    >
                        <span aria-hidden="true" @class(['mt-1.5 size-2 shrink-0 rounded-full', $severityDotClasses[$severity] ?? 'bg-accent'])></span>
                        <span class="min-w-0 flex-1">
                            <span @class(['block truncate text-[13px] text-fg', 'font-semibold' => $isUnread, 'font-medium' => ! $isUnread])>
                                @if ($isUnread)<span class="sr-only">Unread: </span>@endif
                                <span class="sr-only">{{ ucfirst($severity) }}: </span>{{ $notification->data['title'] ?? 'Notification' }}
                            </span>
                            <span class="mt-0.5 block truncate text-xs text-muted">{{ $notification->data['body'] ?? '' }}</span>
                            <span class="mt-1 block text-[11px] text-muted">{{ $notification->created_at?->diffForHumans() }}</span>
                        </span>
                        @if ($isUnread)
                            <span aria-hidden="true" class="mt-1.5 size-1.5 shrink-0 rounded-full bg-accent"></span>
                        @endif
                    </button>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-muted">You're all caught up.</li>
            @endforelse
        </ul>

        <div class="border-t border-border bg-surface-muted px-4 py-2.5 text-center">
            <a href="{{ route('notifications.index') }}" class="text-xs font-semibold text-accent-ink hover:text-accent-ink hover:underline">View all</a>
        </div>
    </section>
</div>
