<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class ProductionBatch extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'production_schedule_id',
        'batch_date',
        'raw_water_volume',
        'bottled_volume',
        'logged_volume',
        'process_loss_percent',
        'status',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'batch_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function productionSchedule(): BelongsTo
    {
        return $this->belongsTo(ProductionSchedule::class);
    }

    public function reference(): string
    {
        return 'B-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

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

    public function stockMovements(): HasManyThrough
    {
        return $this->hasManyThrough(
            StockMovement::class,
            WarehouseStock::class,
            'batch_id',
            'warehouse_stock_id',
        );
    }
}
