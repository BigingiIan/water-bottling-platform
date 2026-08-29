<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionBatch extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'batch_date',
        'raw_water_volume',
        'bottled_volume',
        'status',
        'notes',
    ];

    protected $casts = [
        'batch_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouseStock(): HasMany
    {
        return $this->hasMany(WarehouseStock::class, 'batch_id');
    }

    public function leakageReports(): HasMany
    {
        return $this->hasMany(LeakageReport::class, 'batch_id');
    }
}
