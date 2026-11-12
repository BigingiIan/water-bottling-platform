<?php

namespace App\Services\Invoicing;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    /**
     * Issued invoices that still have money owing.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid], true);
    }

    /**
     * Once issued, an invoice's lines and amounts never change.
     */
    public function isLocked(): bool
    {
        return $this !== self::Draft;
    }

    public function label(): string
    {
        return match ($this) {
            self::PartiallyPaid => 'Partially paid',
            default => ucfirst($this->value),
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-surface-strong text-fg-soft',
            self::Issued => 'bg-accent-soft text-accent-ink',
            self::PartiallyPaid => 'bg-warning-soft text-warning-ink',
            self::Paid => 'bg-success-soft text-success-ink',
            self::Void => 'bg-surface-strong text-muted line-through',
        };
    }

    public function dotClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-subtle',
            self::Issued => 'bg-accent',
            self::PartiallyPaid => 'bg-warning',
            self::Paid => 'bg-success',
            self::Void => 'bg-subtle',
        };
    }
}
