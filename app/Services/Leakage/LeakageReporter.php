<?php

namespace App\Services\Leakage;

use App\Models\LeakageReport;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\StockCount;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Collection;

/**
 * Turns detector assessments into leakage reports. Re-evaluating the same batch or count updates the
 * existing report (matched by detection_key) instead of creating another.
 */
class LeakageReporter
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * @return Collection<int, LeakageReport> reports created or updated
     */
    public function recordProcess(ProductionBatch $batch, Product $product, ?int $lineId, ProcessLossAssessment $assessment, ?User $actor): Collection
    {
        $reports = collect();
        $common = [
            'batch_id' => $batch->id,
            'product_id' => $product->id,
            'production_line_id' => $lineId,
            'report_date' => today()->toDateString(),
            'source' => 'process',
        ];

        if ($assessment->reportsLoss()) {
            $reports->push($this->upsert("process:{$batch->id}:process_loss", [
                ...$common,
                'finding' => 'process_loss',
                'detected_by' => $assessment->lossDetectedBy(),
                'severity' => $assessment->lossSeverity ?? 'review',
                'expected_volume' => $assessment->expectedLitres,
                'actual_volume' => $assessment->loggedLitres,
                'discrepancy' => bcsub($assessment->loggedLitres, $assessment->expectedLitres, 3),
                'loss_percent' => $assessment->lossPercent,
                'z_score' => $assessment->anomaly->zScore,
                'explanation' => $assessment->lossExplanation(),
            ], $actor));
        }

        if ($assessment->gapSeverity !== null) {
            $reports->push($this->upsert("process:{$batch->id}:logging_gap", [
                ...$common,
                'finding' => 'logging_gap',
                'detected_by' => 'rule',
                'severity' => $assessment->gapSeverity,
                'expected_volume' => $assessment->meteredLitres,
                'actual_volume' => $assessment->loggedLitres,
                'discrepancy' => bcsub($assessment->loggedLitres, $assessment->meteredLitres, 3),
                'loss_percent' => $assessment->gapPercent,
                'z_score' => null,
                'explanation' => $assessment->gapExplanation(),
            ], $actor));
        }

        return $reports;
    }

    public function recordStockCount(StockCount $count, Product $product, ShrinkageAssessment $assessment, ?User $actor): ?LeakageReport
    {
        if ($assessment->severity === null) {
            return null;
        }

        $litres = fn (int $units): string => bcmul((string) $units, (string) ($product->volume_litres ?? '1'), 3);

        return $this->upsert("stock:{$count->id}", [
            'product_id' => $product->id,
            'warehouse_location' => $count->warehouse_location,
            'stock_count_id' => $count->id,
            'report_date' => today()->toDateString(),
            'source' => 'stock',
            'finding' => 'stock_shrinkage',
            'detected_by' => 'rule',
            'severity' => $assessment->severity,
            'expected_volume' => $litres($assessment->expectedUnits),
            'actual_volume' => $litres($assessment->countedUnits),
            'discrepancy' => $litres(-$assessment->shrinkageUnits),
            'loss_percent' => $assessment->shrinkagePercent,
            'z_score' => null,
            'explanation' => $assessment->explanation(),
        ], $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(string $detectionKey, array $attributes, ?User $actor): LeakageReport
    {
        $report = LeakageReport::query()->firstOrNew(['detection_key' => $detectionKey]);
        $previousSeverity = $report->exists ? $report->severity : null;
        $report->fill($attributes)->save();

        // Notify on a new finding or when an existing one gets worse; repeat runs stay quiet.
        if (! $report->wasRecentlyCreated && $previousSeverity === $report->severity) {
            return $report;
        }

        if ($report->wasRecentlyCreated || $report->severity === 'investigate') {
            $this->notifications->leakageReportFlagged($report, $actor);
        }

        return $report;
    }
}
