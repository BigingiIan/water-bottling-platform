<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'unit_type',
        'reorder_threshold',
    ];

    public function warehouseStock(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function forecastRecords(): HasMany
    {
        return $this->hasMany(ForecastRecord::class);
    }
}
