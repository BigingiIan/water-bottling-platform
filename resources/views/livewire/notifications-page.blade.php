@php
    $severityDotClasses = [
        'info' => 'bg-accent',
        'warning' => 'bg-warning',
        'critical' => 'bg-danger',
    ];
    $severityBadgeClasses = [
        'info' => 'bg-accent-soft text-accent-ink',
        'warning' => 'bg-warning-soft text-warning-ink',
        'critical' => 'bg-danger-soft text-danger-ink',
    ];
@endphp

<div class="flex flex-col gap-6">
    <x-page-header title="Notifications" :subtitle="$unreadCount.' unread'">
        @if ($unreadCount > 0 || $hasReadNotifications)
            <x-slot:actions>
                @if ($unreadCount > 0)
                    <button type="button" wire:click="markAllAsRead" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">
                        <x-heroicon-o-check class="size-4" aria-hidden="true" />
                        Mark all as read
                    </button>
                @endif
                @if ($hasReadNotifications)
                    <button type="button" wire:click="clearRead" wire:confirm="Delete all read notifications?" class="inline-flex items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3.5 py-2 text-[13px] font-semibold text-fg-soft hover:bg-surface-muted">
                        <x-heroicon-o-trash class="size-4" aria-hidden="true" />
                        Clear read
                    </button>
                @endif
            </x-slot:actions>
        @endif
    </x-page-header>

    <section aria-labelledby="notifications-heading" class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
        <h2 id="notifications-heading" class="sr-only">Notification list</h2>
        <div class="flex flex-wrap items-center gap-2 border-b border-border bg-surface-muted px-[18px] py-3">
            <div role="group" aria-label="Filter by read state" class="flex gap-1">
                @foreach (['all' => 'All', 'unread' => 'Unread'] as $filterValue => $filterLabel)
                    <button
                        type="button"
                        wire:click="$set('readFilter', '{{ $filterValue }}')"
                        aria-pressed="{{ $readFilter === $filterValue ? 'true' : 'false' }}"
                        @class([
                            'rounded-md px-2.5 py-1.5 text-xs font-semibold',
                            'bg-accent text-on-accent' => $readFilter === $filterValue,
                            'border border-border-strong bg-surface text-fg-soft hover:bg-surface-muted' => $readFilter !== $filterValue,
                        ])
                    >{{ $filterLabel }}</button>
                @endforeach
            </div>
            <label class="sr-only" for="notification-severity-filter">Filter by severity</label>
            <select id="notification-severity-filter" wire:model.live="severityFilter" class="rounded-md border border-border-strong bg-surface px-2.5 py-1.5 text-xs text-fg-soft">
                <option value="">All severities</option>
                @foreach ($severities as $severity)
                    <option value="{{ $severity }}">{{ ucfirst($severity) }}</option>
                @endforeach
            </select>
        </div>

        <ul class="divide-y divide-border">
            @forelse ($notifications as $notification)
                @php
                    $isUnread = $notification->read_at === null;
                    $severity = $notification->data['severity'] ?? 'info';
                @endphp
                <li wire:key="notification-{{ $notification->id }}" @class(['flex flex-col gap-3 px-[18px] py-4 sm:flex-row sm:items-start', 'bg-accent-soft/60' => $isUnread])>
                    <span aria-hidden="true" @class(['mt-1.5 hidden size-2 shrink-0 rounded-full sm:block', $severityDotClasses[$severity] ?? 'bg-accent'])></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ \App\Livewire\NotificationBell::safeUrl($notification->data['url'] ?? null) }}" @class(['text-sm text-fg hover:text-accent-ink hover:underline', 'font-semibold' => $isUnread, 'font-medium' => ! $isUnread])>
                                {{ $notification->data['title'] ?? 'Notification' }}
                            </a>
                            <span @class(['inline-flex rounded-full px-2 py-0.5 text-xs font-semibold', $severityBadgeClasses[$severity] ?? 'bg-surface-strong text-fg-soft'])>{{ ucfirst($severity) }}</span>
                            @if ($isUnread)
                                <span class="inline-flex rounded-full bg-accent px-2 py-0.5 text-xs font-semibold text-on-accent">Unread</span>
                            @endif
                        </div>
                        <p class="mt-1 text-sm text-fg-soft">{{ $notification->data['body'] ?? '' }}</p>
                        <p class="mt-1 text-xs text-muted">
                            <time datetime="{{ $notification->created_at?->toIso8601String() }}" title="{{ app(\App\Support\SettingsDate::class)->formatDateTime($notification->created_at) }}">{{ $notification->created_at?->diffForHumans() }}</time>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        @if ($isUnread)
                            <button type="button" wire:click="markAsRead('{{ $notification->id }}')" class="text-xs font-semibold text-accent-ink hover:text-accent-ink hover:underline">Mark read</button>
                        @endif
                        <button type="button" wire:click="deleteNotification('{{ $notification->id }}')" aria-label="Delete notification: {{ $notification->data['title'] ?? 'Notification' }}" class="text-xs font-semibold text-danger-ink hover:text-danger-ink hover:underline">Delete</button>
                    </div>
                </li>
            @empty
                <li><x-empty-state key="notifications" icon="bell" :filtered="$readFilter !== 'all' || $severityFilter !== ''" /></li>
            @endforelse
        </ul>

        @if ($notifications->hasPages())
            <div class="border-t border-border px-[18px] py-3">{{ $notifications->links() }}</div>
        @endif
    </section>
</div>
