<?php

namespace App\Services\Requests;

enum RequestStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    /**
     * Statuses the receiving department may move a request to from this one.
     *
     * @return list<self>
     */
    public function recipientTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Acknowledged, self::InProgress, self::Done, self::Declined],
            self::Acknowledged => [self::InProgress, self::Done, self::Declined],
            self::InProgress => [self::Done, self::Declined],
            self::Done, self::Declined, self::Cancelled => [],
        };
    }

    /**
     * Still waiting on the receiving department.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::Acknowledged, self::InProgress], true);
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::Open->value, self::Acknowledged->value, self::InProgress->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            default => ucfirst($this->value),
        };
    }

    /**
     * Verb for the action button that moves a request into this status.
     */
    public function actionLabel(): string
    {
        return match ($this) {
            self::Acknowledged => 'Acknowledge',
            self::InProgress => 'Start work',
            self::Done => 'Mark done',
            self::Declined => 'Decline',
            self::Cancelled => 'Cancel request',
            self::Open => 'Reopen',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-blue-50 text-[#1d4ed8]',
            self::Acknowledged => 'bg-purple-100 text-[#7e22ce]',
            self::InProgress => 'bg-amber-50 text-[#b45309]',
            self::Done => 'bg-green-50 text-[#15803d]',
            self::Declined => 'bg-red-50 text-[#b91c1c]',
            self::Cancelled => 'bg-gray-100 text-gray-600',
        };
    }

    public function dotClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-blue-500',
            self::Acknowledged => 'bg-purple-500',
            self::InProgress => 'bg-amber-500',
            self::Done => 'bg-green-500',
            self::Declined => 'bg-red-500',
            self::Cancelled => 'bg-gray-400',
        };
    }
}
