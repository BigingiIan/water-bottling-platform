<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'stock_id',
        'quantity',
        'unit_price',
        'total_value',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
    ];

    const CREATED_AT = 'transaction_date';
    const UPDATED_AT = null;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(WarehouseStock::class, 'stock_id');
    }
}
