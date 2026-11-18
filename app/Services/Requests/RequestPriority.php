<?php

namespace App\Services\Requests;

enum RequestPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case Urgent = 'urgent';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Low => 'bg-surface-strong text-fg-soft',
            self::Normal => 'bg-accent-soft text-accent-ink',
            self::Urgent => 'bg-danger-soft text-danger-ink',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Low => 'arrow-down',
            self::Normal => 'minus',
            self::Urgent => 'exclamation-triangle',
        };
    }
}
