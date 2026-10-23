<?php

namespace App\Models;

use App\Services\Orders\Money;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
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
        static::saving(function (OrderItem $item): void {
            if ($item->quantity < 1) {
                throw new InvalidArgumentException('Order item quantity must be greater than zero.');
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function effectiveUnitPrice(): ?string
    {
        $price = $this->unit_price ?? $this->product?->unit_price_kes;

        return $price === null ? null : Money::normalize((string) $price);
    }

    public function effectiveLineTotal(): string
    {
        if ($this->line_total !== null) {
            return Money::normalize((string) $this->line_total);
        }

        $price = $this->effectiveUnitPrice();

        return $price === null ? '0.00' : Money::multiply($this->quantity, $price);
    }
}
