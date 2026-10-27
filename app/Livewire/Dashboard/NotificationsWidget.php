<?php

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;

class NotificationsWidget extends WidgetComponent
{
    private const LATEST_NOTIFICATIONS = 5;

    public static function key(): string
    {
        return 'notifications';
    }

    public function render(): View
    {
        $user = $this->authorizeWidget();

        return view('livewire.dashboard.notifications-widget', [
            'notifications' => $user->notifications()->latest()->limit(self::LATEST_NOTIFICATIONS)->get(),
        ]);
    }
}
