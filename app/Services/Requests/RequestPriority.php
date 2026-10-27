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
            self::Low => 'bg-gray-100 text-gray-600',
            self::Normal => 'bg-blue-50 text-[#1d4ed8]',
            self::Urgent => 'bg-red-50 text-[#b91c1c]',
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
