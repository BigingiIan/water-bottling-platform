<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SystemNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{type: string, title: string, body: string, url: string, severity: 'info'|'warning'|'critical', key: string}  $payload
     */
    public function __construct(public array $payload) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{type: string, title: string, body: string, url: string, severity: 'info'|'warning'|'critical', key: string}
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
