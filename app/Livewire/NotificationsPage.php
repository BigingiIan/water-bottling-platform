<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\UserPreferenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Notifications', 'breadcrumbSection' => 'Account', 'activeItem' => 'Notifications'])]
class NotificationsPage extends Component
{
    use WithPagination;

    private const READ_FILTERS = ['all', 'unread'];

    private const SEVERITIES = ['info', 'warning', 'critical'];

    #[Url(as: 'show', except: 'all')]
    public string $readFilter = 'all';

    #[Url(except: '')]
    public string $severityFilter = '';

    public function mount(): void
    {
        $this->normalizeFilters();
    }

    public function updatedReadFilter(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    public function updatedSeverityFilter(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    #[On('notifications-updated')]
    public function refreshNotifications(): void
    {
        // No-op: receiving the event re-renders the list after bell actions.
    }

    public function markAsRead(string $notificationId): void
    {
        $this->user()->notifications()->whereKey($notificationId)->firstOrFail()->markAsRead();
        $this->dispatch('notifications-updated');
    }

    public function markAllAsRead(): void
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);
        $this->dispatch('notifications-updated');
    }

    public function deleteNotification(string $notificationId): void
    {
        $this->user()->notifications()->whereKey($notificationId)->firstOrFail()->delete();
        $this->dispatch('notifications-updated');
    }

    public function clearRead(): void
    {
        $this->user()->readNotifications()->delete();
        $this->resetPage();
        $this->dispatch('notifications-updated');
    }

    public function render(): View
    {
        $user = $this->user();

        $notifications = $user->notifications()
            ->when($this->readFilter === 'unread', fn (Builder $query): Builder => $query->whereNull('read_at'))
            ->when($this->severityFilter !== '', fn (Builder $query): Builder => $query->where('data->severity', $this->severityFilter))
            ->latest()
            ->paginate(app(UserPreferenceService::class)->pageSize($user, 20));

        return view('livewire.notifications-page', [
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
            'hasReadNotifications' => $user->readNotifications()->exists(),
            'severities' => self::SEVERITIES,
        ]);
    }

    private function normalizeFilters(): void
    {
        if (! in_array($this->readFilter, self::READ_FILTERS, true)) {
            $this->readFilter = 'all';
        }

        if ($this->severityFilter !== '' && ! in_array($this->severityFilter, self::SEVERITIES, true)) {
            $this->severityFilter = '';
        }
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
