<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailySkuFeature extends Model
{
    protected $fillable = [
        'product_sku',
        'date',
        'is_promotion',
        'is_public_holiday',
        'is_school_term',
        'sales_data_covered',
        'temperature_c',
    ];

    protected $casts = [
        'is_promotion' => 'boolean',
        'is_public_holiday' => 'boolean',
        'is_school_term' => 'boolean',
        'sales_data_covered' => 'boolean',
        'temperature_c' => 'float',
    ];
}
