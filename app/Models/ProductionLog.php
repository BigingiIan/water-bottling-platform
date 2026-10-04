<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionLog extends Model
{
    protected $fillable = [
        'production_schedule_id',
        'operator_id',
        'units_logged',
        'logged_at',
        'quality_status',
        'notes',
    ];

    protected $casts = [
        'units_logged' => 'integer',
        'logged_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<ProductionSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProductionSchedule::class, 'production_schedule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
