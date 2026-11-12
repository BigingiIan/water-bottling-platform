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
            self::Draft => 'bg-surface-strong text-fg-soft',
            self::Confirmed => 'bg-accent-soft text-accent-ink',
            self::Dispatched => 'bg-warning-soft text-warning-ink',
            self::Delivered => 'bg-success-soft text-success-ink',
            self::Cancelled => 'bg-danger-soft text-danger-ink',
        };
    }

    public function dotClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-subtle',
            self::Confirmed => 'bg-accent',
            self::Dispatched => 'bg-warning',
            self::Delivered => 'bg-success',
            self::Cancelled => 'bg-danger',
        };
    }
}
