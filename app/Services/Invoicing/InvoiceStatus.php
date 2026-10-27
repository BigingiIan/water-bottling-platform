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
            self::Draft => 'bg-gray-100 text-gray-700',
            self::Issued => 'bg-blue-50 text-[#1d4ed8]',
            self::PartiallyPaid => 'bg-amber-50 text-[#b45309]',
            self::Paid => 'bg-green-50 text-[#15803d]',
            self::Void => 'bg-gray-100 text-gray-500 line-through',
        };
    }

    public function dotClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-gray-400',
            self::Issued => 'bg-blue-500',
            self::PartiallyPaid => 'bg-amber-500',
            self::Paid => 'bg-green-500',
            self::Void => 'bg-gray-400',
        };
    }
}
