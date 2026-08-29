<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseStock extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'batch_id',
        'product_id',
        'current_level',
        'warehouse_location',
        'minimum_threshold',
    ];

    protected $casts = [
        'last_updated' => 'datetime',
    ];

    const UPDATED_AT = 'last_updated';

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function salesTransactions(): HasMany
    {
        return $this->hasMany(SalesTransaction::class, 'stock_id');
    }
}
