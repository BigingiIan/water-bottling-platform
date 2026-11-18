<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'forecast_date',
        'predicted_demand',
        'horizon_days',
        'model_version',
        'rmse_score',
        'generated_at',
    ];

    protected $casts = [
        'forecast_date' => 'date',
        'generated_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Orders the most recently generated forecast first, so every page picks the same record for a day.
     *
     * @param  Builder<ForecastRecord>  $query
     * @return Builder<ForecastRecord>
     */
    public function scopeLatestGenerated(Builder $query): Builder
    {
        return $query->orderByDesc('generated_at')->orderByDesc('id');
    }
}
