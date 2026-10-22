<?php

namespace App\Models;

use App\Support\LeakageReportFlag;
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

    public function flag(): string
    {
        return LeakageReportFlag::fromVolumes(
            (float) $this->expected_volume,
            (float) $this->actual_volume,
        );
    }
}
