<?php

namespace App\Models;

use App\Services\Leakage\LeakageDetector;
use App\Support\LeakageReportFlag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeakageReport extends Model
{
    protected $fillable = [
        'batch_id',
        'product_id',
        'production_line_id',
        'warehouse_location',
        'stock_count_id',
        'expected_volume',
        'actual_volume',
        'discrepancy',
        'report_date',
        'source',
        'finding',
        'detected_by',
        'severity',
        'loss_percent',
        'z_score',
        'explanation',
        'detection_key',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'resolution_note',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'loss_percent' => 'decimal:3',
            'z_score' => 'decimal:3',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Hand-entered reports are graded with the same detector thresholds as automatic ones.
        static::saving(function (LeakageReport $report): void {
            if (($report->source ?? 'manual') === 'manual' && $report->isDirty(['expected_volume', 'actual_volume', 'discrepancy'])) {
                $grading = app(LeakageDetector::class)->gradeManual((string) $report->expected_volume, (string) $report->actual_volume);
                $report->loss_percent = $grading['lossPercent'];
                $report->severity = $grading['severity'];
                $report->explanation ??= $grading['explanation'];
            }
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productionLine(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class);
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function flag(): string
    {
        return LeakageReportFlag::fromSeverity($this->severity);
    }
}
