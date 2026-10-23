<?php

namespace App\Models;

use App\Services\Orders\Money;
use App\Services\Orders\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'order_number',
        'customer_id',
        'status',
        'order_date',
        'requested_date',
        'confirmed_at',
        'dispatched_at',
        'delivered_at',
        'cancelled_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'order_date' => 'date',
            'requested_date' => 'date',
            'confirmed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * The invoice that is not void, if any.
     */
    public function liveInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'orders_invoice_key');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Confirmed lines carry their own price; drafts are valued at the products' current prices.
     */
    public function total(): string
    {
        return Money::sum($this->items->map(fn (OrderItem $item): string => $item->effectiveLineTotal())->all());
    }
}
