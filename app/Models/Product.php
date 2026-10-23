<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'name',
        'unit_type',
        'reorder_threshold',
        'unit_price_kes',
        'volume_litres',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_kes' => 'decimal:2',
            'volume_litres' => 'decimal:3',
        ];
    }

    public function warehouseStock(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function forecastRecords(): HasMany
    {
        return $this->hasMany(ForecastRecord::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
