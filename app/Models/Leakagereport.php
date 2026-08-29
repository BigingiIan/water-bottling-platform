<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeakageReport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'batch_id',
        'expected_volume',
        'actual_volume',
        'discrepancy',
        'report_date',
    ];

    protected $casts = [
        'report_date' => 'date',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'batch_id');
    }
}
