<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCount extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'warehouse_location',
        'counted_units',
        'opening_units',
        'production_in',
        'sales_out',
        'adjustments_net',
        'expected_units',
        'system_units',
        'shrinkage_units',
        'previous_count_id',
        'last_movement_id',
        'user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'counted_units' => 'integer',
            'opening_units' => 'integer',
            'production_in' => 'integer',
            'sales_out' => 'integer',
            'adjustments_net' => 'integer',
            'expected_units' => 'integer',
            'system_units' => 'integer',
            'shrinkage_units' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
