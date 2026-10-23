@php
    $severityDotClasses = [
        'info' => 'bg-blue-500',
        'warning' => 'bg-amber-500',
        'critical' => 'bg-red-500',
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
        class="relative flex size-[34px] items-center justify-center rounded-md bg-gray-100 text-gray-600 transition-colors hover:bg-gray-200 hover:text-gray-800"
    >
        <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" class="size-[18px]">
            <path d="M15 7a5 5 0 0 0-10 0c0 5-2 5-2 7h14c0-2-2-2-2-7ZM8 17h4" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" />
        </svg>
        @if ($unreadCount > 0)
            <span aria-hidden="true" class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold leading-none text-white">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <section
        id="notification-dropdown"
        aria-label="Notifications"
        x-show="$wire.open"
        @unless ($open) style="display: none" @endunless
        class="absolute right-0 z-30 mt-2 w-[360px] max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg"
    >
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
            <h2 class="text-[13px] font-bold text-gray-900">Notifications</h2>
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllAsRead" class="text-xs font-semibold text-blue-700 hover:text-blue-900 hover:underline">Mark all as read</button>
            @endif
        </div>

        <ul class="max-h-[420px] divide-y divide-gray-100 overflow-y-auto">
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
                            'flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-gray-50 focus:bg-gray-50 focus:outline-none',
                            'bg-blue-50/60' => $isUnread,
                        ])
                    >
                        <span aria-hidden="true" @class(['mt-1.5 size-2 shrink-0 rounded-full', $severityDotClasses[$severity] ?? 'bg-blue-500'])></span>
                        <span class="min-w-0 flex-1">
                            <span @class(['block truncate text-[13px] text-gray-900', 'font-semibold' => $isUnread, 'font-medium' => ! $isUnread])>
                                @if ($isUnread)<span class="sr-only">Unread: </span>@endif
                                <span class="sr-only">{{ ucfirst($severity) }}: </span>{{ $notification->data['title'] ?? 'Notification' }}
                            </span>
                            <span class="mt-0.5 block truncate text-xs text-gray-500">{{ $notification->data['body'] ?? '' }}</span>
                            <span class="mt-1 block text-[11px] text-gray-400">{{ $notification->created_at?->diffForHumans() }}</span>
                        </span>
                        @if ($isUnread)
                            <span aria-hidden="true" class="mt-1.5 size-1.5 shrink-0 rounded-full bg-blue-600"></span>
                        @endif
                    </button>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-gray-500">You're all caught up.</li>
            @endforelse
        </ul>

        <div class="border-t border-gray-100 bg-gray-50 px-4 py-2.5 text-center">
            <a href="{{ route('notifications.index') }}" class="text-xs font-semibold text-blue-700 hover:text-blue-900 hover:underline">View all</a>
        </div>
    </section>
</div>
