<?php

namespace App\Models;

use App\Services\Invoicing\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

class Invoice extends Model
{
    /**
     * Columns that are frozen once the invoice is issued. Corrections go through void and reissue.
     *
     * @var array<int, string>
     */
    public const LOCKED_COLUMNS = [
        'invoice_number', 'order_id', 'customer_id', 'issue_date', 'due_date', 'prices_include_vat',
        'vat_rate', 'subtotal', 'vat_amount', 'total', 'issued_at', 'issued_by',
    ];

    protected $fillable = [
        'invoice_number',
        'order_id',
        'orders_invoice_key',
        'customer_id',
        'status',
        'issue_date',
        'due_date',
        'prices_include_vat',
        'vat_rate',
        'subtotal',
        'vat_amount',
        'total',
        'amount_paid',
        'void_reason',
        'voided_at',
        'voided_by',
        'issued_at',
        'issued_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'prices_include_vat' => 'boolean',
            'vat_rate' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            $originalStatus = InvoiceStatus::from((string) $invoice->getRawOriginal('status'));

            if ($originalStatus->isLocked() && $invoice->isDirty(self::LOCKED_COLUMNS)) {
                throw new LogicException("Invoice {$invoice->invoice_number} has been issued and cannot be changed; void it and create a new invoice.");
            }
        });

        static::deleting(function (Invoice $invoice): void {
            if ($invoice->status->isLocked()) {
                throw new LogicException('Issued invoices cannot be deleted; void them instead.');
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function balance(): string
    {
        return bcsub((string) $this->total, (string) $this->amount_paid, 2);
    }

    /**
     * Overdue is computed, never stored: money still owing and the due date has passed.
     */
    public function isOverdue(?Carbon $today = null): bool
    {
        return $this->status->isOpen()
            && $this->due_date !== null
            && ($today ?? today())->greaterThan($this->due_date);
    }

    public function scopeOverdue(Builder $query, ?Carbon $today = null): Builder
    {
        return $query
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->whereDate('due_date', '<', ($today ?? today())->toDateString());
    }

    public function displayNumber(): string
    {
        return $this->invoice_number ?? 'Draft #'.$this->id;
    }
}
