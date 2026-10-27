<?php

namespace App\Services\Leakage;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionSchedule;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Completing a run completes its batch (one run = one batch) and runs Stage A leakage detection on it.
 */
class ProductionRunCompletion
{
    public function __construct(
        private LeakageDetector $detector,
        private LeakageReporter $reporter,
        private NotificationService $notifications,
    ) {}

    public function complete(ProductionSchedule $run, string $rawWaterLitres, string $meteredBottledLitres, User $user): ?ProcessLossAssessment
    {
        $assessment = DB::transaction(function () use ($run, $rawWaterLitres, $meteredBottledLitres, $user): ?ProcessLossAssessment {
            $run = ProductionSchedule::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($run->status !== 'active') {
                throw new InvalidArgumentException('Only active runs can be completed.');
            }

            $run->update(['status' => 'complete']);
            $batch = ProductionBatch::query()->updateOrCreate(
                ['production_schedule_id' => $run->id],
                [
                    'user_id' => $user->id,
                    'batch_date' => $run->scheduled_date,
                    'raw_water_volume' => $rawWaterLitres,
                    'bottled_volume' => $meteredBottledLitres,
                    'status' => 'completed',
                    'completed_at' => now(),
                    'notes' => "Production run {$run->product_sku} on ".$run->scheduled_date->toDateString(),
                ],
            );

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'production.run_completed',
                'table_name' => 'production_schedules',
                'new_values' => [
                    'production_schedule_id' => $run->id,
                    'batch_id' => $batch->id,
                    'raw_water_volume' => $rawWaterLitres,
                    'bottled_volume' => $meteredBottledLitres,
                ],
            ]);

            $product = Product::query()->where('unit_type', $run->product_sku)->first();

            // Without a product volume, logged bottles cannot be turned into litres; the batch is still completed.
            if ($product?->volume_litres === null) {
                return null;
            }

            $assessment = $this->detector->evaluateBatch($batch);
            $batch->update([
                'logged_volume' => $assessment->loggedLitres,
                'process_loss_percent' => $assessment->lossPercent,
            ]);
            $this->reporter->recordProcess($batch, $product, $run->production_line_id, $assessment, $user);

            return $assessment;
        });

        $this->notifications->productionRunCompleted($run->fresh(), $user);

        return $assessment;
    }
}
