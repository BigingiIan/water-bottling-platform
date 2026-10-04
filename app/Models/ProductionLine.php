<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionLine extends Model
{
    protected $fillable = [
        'name',
        'status',
    ];

    /**
     * @return HasMany<ProductionSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ProductionSchedule::class);
    }
}
