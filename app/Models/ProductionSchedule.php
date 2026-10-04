<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionSchedule extends Model
{
    protected $fillable = [
        'product_sku',
        'production_line_id',
        'scheduled_date',
        'scheduled_time',
        'target_units',
        'status',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'target_units' => 'integer',
    ];

    /**
     * @return BelongsTo<ProductionLine, $this>
     */
    public function productionLine(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class);
    }

    /**
     * @return HasMany<ProductionLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ProductionLog::class);
    }
}
