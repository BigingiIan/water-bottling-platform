<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'forecast_date' => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
