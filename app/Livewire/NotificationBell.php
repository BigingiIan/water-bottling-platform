<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBell extends Component
{
    private const DROPDOWN_LIMIT = 8;

    public bool $open = false;

    #[On('notifications-updated')]
    public function refreshNotifications(): void
    {
        // No-op: receiving the event re-renders the unread count and latest notifications.
    }

    public function openNotification(string $notificationId): void
    {
        $notification = $this->user()->notifications()->whereKey($notificationId)->firstOrFail();
        $notification->markAsRead();
        $this->open = false;

        $this->redirect(self::safeUrl($notification->data['url'] ?? null));
    }

    public function markAllAsRead(): void
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);
        $this->dispatch('notifications-updated');
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.notification-bell', [
            'unreadCount' => $user->unreadNotifications()->count(),
            'notifications' => $user->notifications()->latest()->limit(self::DROPDOWN_LIMIT)->get(),
        ]);
    }

    /**
     * Only same-site relative paths are followed; anything else falls back to the notifications page.
     */
    public static function safeUrl(mixed $url): string
    {
        if (is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        return route('notifications.index', absolute: false);
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
