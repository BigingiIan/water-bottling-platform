<?php

namespace App\Models;

use App\Services\Invoicing\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (InvoiceItem $item): void {
            $status = Invoice::query()->whereKey($item->invoice_id)->value('status');

            if ($status !== null && ($status instanceof InvoiceStatus ? $status : InvoiceStatus::from($status))->isLocked()) {
                throw new LogicException('Lines on an issued invoice cannot be changed.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
