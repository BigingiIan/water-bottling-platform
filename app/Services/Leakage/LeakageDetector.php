<?php

namespace App\Services\Leakage;

use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\StockCount;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every leakage formula in one place, so each report can show exactly how it was reached.
 */
class LeakageDetector
{
    private const SCALE = 3;

    private const ROBUST_Z_CONSTANT = 0.6745;

    /**
     * Iglewicz & Hoaglin: when MAD is 0, scale the mean absolute deviation instead.
     */
    private const MEAN_AD_CONSTANT = 1.253314;

    /**
     * Movement types counted as goods coming in for the shrinkage expectation.
     *
     * @var array<int, string>
     */
    private const INBOUND_TYPES = ['production', 'stock_in', 'return'];

    /**
     * @var array<int, string>
     */
    private const ADJUSTMENT_TYPES = ['adjustment', 'leakage_writeoff'];

    public const COUNT_REFERENCE_PREFIX = 'COUNT-';

    public function __construct(private SettingsService $settings) {}

    public function reviewPercent(): string
    {
        return $this->decimal($this->settings->get('leakage.review_percent', 2.0));
    }

    public function investigatePercent(): string
    {
        return $this->decimal($this->settings->get('leakage.investigate_percent', 5.0));
    }

    public function normalLossPercent(): string
    {
        return $this->decimal($this->settings->get('leakage.normal_process_loss_percent', 3.0));
    }

    /**
     * Below review → no severity; review up to and including investigate → review; above → investigate.
     */
    public function severityFor(?string $lossPercent): ?string
    {
        if ($lossPercent === null || bccomp($lossPercent, $this->reviewPercent(), self::SCALE) < 0) {
            return null;
        }

        return bccomp($lossPercent, $this->investigatePercent(), self::SCALE) <= 0 ? 'review' : 'investigate';
    }

    /**
     * Manual reports keep their historical meaning: any deviation, gain or loss, against the expected volume.
     *
     * @return array{lossPercent: ?string, severity: ?string, explanation: ?string}
     */
    public function gradeManual(string $expectedVolume, string $actualVolume): array
    {
        $expected = $this->decimal($expectedVolume);

        if (bccomp($expected, '0', self::SCALE) <= 0) {
            return ['lossPercent' => null, 'severity' => null, 'explanation' => null];
        }

        $difference = bcsub($this->decimal($actualVolume), $expected, self::SCALE);
        $lossPercent = $this->percentOf(ltrim($difference, '-'), $expected);

        return [
            'lossPercent' => $lossPercent,
            'severity' => $this->severityFor($lossPercent),
            'explanation' => sprintf(
                'Recorded by hand: %s L expected, %s L actual, a %s%% difference.',
                ProcessLossAssessment::litres($expected),
                ProcessLossAssessment::litres($actualVolume),
                ProcessLossAssessment::percent($lossPercent),
            ),
        ];
    }

    /**
     * Stage A: water lost between intake and bottling, and metered vs logged bottles, for a completed run's batch.
     */
    public function evaluateBatch(ProductionBatch $batch): ProcessLossAssessment
    {
        $run = $batch->productionSchedule()->with('productionLine')->first()
            ?? throw new InvalidArgumentException("Batch {$batch->reference()} is not linked to a production run.");
        $product = Product::query()->where('unit_type', $run->product_sku)->firstOrFail();

        if ($product->volume_litres === null) {
            throw new InvalidArgumentException("Product {$product->unit_type} has no volume, so logged litres cannot be computed.");
        }

        $loggedUnits = (int) $run->logs()->sum('units_logged');
        $loggedLitres = bcmul((string) $loggedUnits, $this->decimal($product->volume_litres), self::SCALE);
        $raw = $this->decimal($batch->raw_water_volume);
        $normalLoss = $this->normalLossPercent();
        $expected = bcmul($raw, bcsub('1', bcdiv($normalLoss, '100', 6), 6), self::SCALE);
        $lossLitres = bcsub($expected, $loggedLitres, self::SCALE);
        // Loss beyond normal, as a share of what should have been bottled; this is what thresholds apply to.
        $lossPercent = bccomp($expected, '0', self::SCALE) > 0 ? $this->percentOf($lossLitres, $expected) : null;
        $totalLossPercent = bccomp($raw, '0', self::SCALE) > 0 ? $this->percentOf(bcsub($raw, $loggedLitres, self::SCALE), $raw) : null;
        $metered = $this->decimal($batch->bottled_volume);
        $gapPercent = bccomp($metered, '0', self::SCALE) > 0
            ? $this->percentOf(ltrim(bcsub($metered, $loggedLitres, self::SCALE), '-'), $metered)
            : null;

        return new ProcessLossAssessment(
            batchReference: $batch->reference(),
            sku: $product->unit_type,
            lineName: $run->productionLine?->name,
            rawLitres: $raw,
            normalLossPercent: $normalLoss,
            expectedLitres: $expected,
            loggedLitres: $loggedLitres,
            loggedUnits: $loggedUnits,
            lossLitres: $lossLitres,
            totalLossPercent: $totalLossPercent,
            lossPercent: $lossPercent,
            lossSeverity: $this->severityFor($lossPercent),
            anomaly: $lossPercent === null
                ? new AnomalyAssessment('skipped', null, 0, null, reason: 'no intake volume recorded')
                : $this->anomalyFor($product, $run->production_line_id, (float) $lossPercent, $batch->id),
            meteredLitres: $metered,
            gapPercent: $gapPercent,
            gapSeverity: $this->severityFor($gapPercent),
        );
    }

    /**
     * Robust z-score of a batch's loss % against recent completed batches of the same product (and line
     * when it has enough history). One-sided: only unusually high loss is flagged.
     */
    public function anomalyFor(Product $product, ?int $lineId, float $lossPercent, ?int $excludeBatchId = null): AnomalyAssessment
    {
        $baseline = $lineId === null ? [] : $this->baselineLosses($product, $lineId, $excludeBatchId);
        $scope = 'product and line';

        if (count($baseline) < AnomalyAssessment::MINIMUM_BATCHES) {
            $baseline = $this->baselineLosses($product, null, $excludeBatchId);
            $scope = 'product';
        }

        $size = count($baseline);

        if ($size < AnomalyAssessment::MINIMUM_BATCHES) {
            return new AnomalyAssessment('skipped', null, $size, null, reason: "not enough history ({$size} of ".AnomalyAssessment::MINIMUM_BATCHES.' completed batches)');
        }

        $median = self::median($baseline);
        $mad = self::median(array_map(fn (float $value): float => abs($value - $median), $baseline));

        if ($mad > 0) {
            $zScore = self::ROBUST_Z_CONSTANT * ($lossPercent - $median) / $mad;
        } else {
            $meanAbsoluteDeviation = array_sum(array_map(fn (float $value): float => abs($value - $median), $baseline)) / $size;

            if ($meanAbsoluteDeviation == 0.0) {
                return new AnomalyAssessment('skipped', null, $size, $scope, $median, 0.0, 'the baseline batches all have the same loss, so there is no spread to compare against');
            }

            $zScore = ($lossPercent - $median) / (self::MEAN_AD_CONSTANT * $meanAbsoluteDeviation);
        }

        return new AnomalyAssessment(
            $zScore >= AnomalyAssessment::THRESHOLD ? 'unusual' : 'normal',
            round($zScore, 3),
            $size,
            $scope,
            $median,
            $mad,
        );
    }

    /**
     * Stage B: compares a physical count with the level the recorded movements say should be there.
     */
    public function evaluateCount(Product $product, ?string $location, int $countedUnits, ?StockCount $previousCount): ShrinkageAssessment
    {
        $lineIds = DB::table('warehouse_stock')
            ->where('product_id', $product->id)
            ->when($location === null, fn ($query) => $query->whereNull('warehouse_location'), fn ($query) => $query->where('warehouse_location', $location))
            ->pluck('id')
            ->all();
        $systemUnits = (int) DB::table('warehouse_stock')->whereIn('id', $lineIds)->sum('current_level');
        $movements = DB::table('stock_movements')
            ->whereIn('warehouse_stock_id', $lineIds)
            ->when($previousCount?->last_movement_id !== null, fn ($query) => $query->where('id', '>', $previousCount->last_movement_id))
            ->where(fn ($query) => $query->whereNull('reference')->orWhere('reference', 'not like', self::COUNT_REFERENCE_PREFIX.'%'))
            ->get(['type', 'quantity_change']);

        $productionIn = (int) $movements->whereIn('type', self::INBOUND_TYPES)->sum('quantity_change');
        $salesOut = (int) -$movements->where('type', 'sale')->sum('quantity_change');
        $adjustmentsNet = (int) $movements->whereIn('type', self::ADJUSTMENT_TYPES)->sum('quantity_change');
        $openingFromCount = $previousCount !== null;
        // Without an earlier count, the opening level is what was on hand before any recorded movement.
        $opening = $openingFromCount
            ? $previousCount->counted_units
            : $systemUnits - (int) $movements->sum('quantity_change');
        $expected = $opening + $productionIn - $salesOut + $adjustmentsNet;
        $shrinkage = $expected - $countedUnits;
        $shrinkagePercent = $expected > 0 ? $this->percentOf((string) $shrinkage, (string) $expected) : null;

        return new ShrinkageAssessment(
            sku: $product->unit_type,
            location: $location,
            openingUnits: $opening,
            openingFromCount: $openingFromCount,
            productionIn: $productionIn,
            salesOut: $salesOut,
            adjustmentsNet: $adjustmentsNet,
            expectedUnits: $expected,
            systemUnits: $systemUnits,
            countedUnits: $countedUnits,
            shrinkageUnits: $shrinkage,
            shrinkagePercent: $shrinkagePercent,
            severity: $this->severityFor($shrinkagePercent),
        );
    }

    /**
     * @return array<int, float>
     */
    private function baselineLosses(Product $product, ?int $lineId, ?int $excludeBatchId): array
    {
        return DB::table('production_batches')
            ->join('production_schedules', 'production_schedules.id', '=', 'production_batches.production_schedule_id')
            ->where('production_schedules.product_sku', $product->unit_type)
            ->when($lineId !== null, fn ($query) => $query->where('production_schedules.production_line_id', $lineId))
            ->where('production_batches.status', 'completed')
            ->whereNotNull('production_batches.process_loss_percent')
            ->when($excludeBatchId !== null, fn ($query) => $query->where('production_batches.id', '!=', $excludeBatchId))
            ->orderByDesc('production_batches.completed_at')
            ->orderByDesc('production_batches.id')
            ->limit(AnomalyAssessment::BASELINE_BATCHES)
            ->pluck('production_batches.process_loss_percent')
            ->map(fn (mixed $value): float => (float) $value)
            ->all();
    }

    /**
     * @param  array<int, float>  $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function percentOf(string $part, string $whole): string
    {
        return bcdiv(bcmul($part, '100', 6), $whole, self::SCALE);
    }

    private function decimal(mixed $value): string
    {
        return bcadd(is_float($value) ? sprintf('%.6F', $value) : (string) $value, '0', self::SCALE);
    }
}
