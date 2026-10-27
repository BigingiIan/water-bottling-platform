<?php

namespace App\Services\Orders;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::Draft => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Dispatched, self::Cancelled],
            self::Dispatched => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->nextStatuses(), true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Confirmed], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-gray-100 text-gray-700',
            self::Confirmed => 'bg-blue-50 text-[#1d4ed8]',
            self::Dispatched => 'bg-amber-50 text-[#b45309]',
            self::Delivered => 'bg-green-50 text-[#15803d]',
            self::Cancelled => 'bg-red-50 text-[#b91c1c]',
        };
    }

    public function dotClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-gray-400',
            self::Confirmed => 'bg-blue-500',
            self::Dispatched => 'bg-amber-500',
            self::Delivered => 'bg-green-500',
            self::Cancelled => 'bg-red-500',
        };
    }
}
