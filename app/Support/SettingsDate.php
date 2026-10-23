<?php

namespace App\Support;

use App\Services\SettingsService;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class SettingsDate
{
    public function __construct(private SettingsService $settings) {}

    public function formatDate(DateTimeInterface|string|null $date): string
    {
        if ($date === null) {
            return '—';
        }

        return Carbon::parse($date)->format((string) $this->settings->get('ui.date_format', 'd M Y'));
    }

    public function formatInstantDate(DateTimeInterface|string|null $date): string
    {
        if ($date === null) {
            return '—';
        }

        $timezone = (string) $this->settings->get('organisation.timezone', 'Africa/Nairobi');

        return Carbon::parse($date, 'UTC')
            ->setTimezone($timezone)
            ->format((string) $this->settings->get('ui.date_format', 'd M Y'));
    }

    public function formatDateTime(DateTimeInterface|string|null $date, string $timeFormat = 'H:i'): string
    {
        if ($date === null) {
            return '—';
        }

        $timezone = (string) $this->settings->get('organisation.timezone', 'Africa/Nairobi');

        return Carbon::parse($date, 'UTC')
            ->setTimezone($timezone)
            ->format((string) $this->settings->get('ui.date_format', 'd M Y').", {$timeFormat}");
    }

    public function formatTime(DateTimeInterface|string|null $date, string $timeFormat = 'H:i'): string
    {
        if ($date === null) {
            return '—';
        }

        $timezone = (string) $this->settings->get('organisation.timezone', 'Africa/Nairobi');

        return Carbon::parse($date, 'UTC')
            ->setTimezone($timezone)
            ->format($timeFormat);
    }
}
