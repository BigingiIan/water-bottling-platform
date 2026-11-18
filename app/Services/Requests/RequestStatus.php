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
            self::Open => 'bg-accent-soft text-accent-ink',
            self::Acknowledged => 'bg-highlight-soft text-highlight-ink',
            self::InProgress => 'bg-warning-soft text-warning-ink',
            self::Done => 'bg-success-soft text-success-ink',
            self::Declined => 'bg-danger-soft text-danger-ink',
            self::Cancelled => 'bg-surface-strong text-fg-soft',
        };
    }

    public function dotClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-accent',
            self::Acknowledged => 'bg-highlight',
            self::InProgress => 'bg-warning',
            self::Done => 'bg-success',
            self::Declined => 'bg-danger',
            self::Cancelled => 'bg-subtle',
        };
    }
}
