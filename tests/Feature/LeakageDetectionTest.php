<?php

namespace Tests\Feature;

use App\Models\LeakageReport;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionLine;
use App\Models\ProductionLog;
use App\Models\ProductionSchedule;
use App\Models\Role;
use App\Models\User;
use App\Services\Leakage\LeakageDetector;
use App\Services\Leakage\LeakageReporter;
use App\Services\Leakage\ProductionRunCompletion;
use App\Services\SettingsService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeakageDetectionTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Product $product;

    private ProductionLine $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        $this->operator = User::factory()->create(['role_id' => Role::query()->where('slug', 'production-staff')->valueOrFail('id')]);
        $this->product = Product::factory()->create(['unit_type' => '1L', 'name' => 'Bottled Water 1L', 'volume_litres' => '1.000']);
        $this->line = ProductionLine::query()->create(['name' => 'Line 01', 'status' => 'active']);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function severityBoundaries(): array
    {
        return [
            'just below review' => ['1.999', null],
            'at review' => ['2.000', 'review'],
            'above review' => ['2.001', 'review'],
            'at investigate' => ['5.000', 'review'],
            'just above investigate' => ['5.001', 'investigate'],
        ];
    }

    #[DataProvider('severityBoundaries')]
    public function test_severity_threshold_boundaries(string $lossPercent, ?string $expected): void
    {
        $this->assertSame($expected, app(LeakageDetector::class)->severityFor($lossPercent));
    }

    public function test_normal_process_loss_is_subtracted_before_measuring_loss(): void
    {
        $batch = $this->completedBatch(raw: '1000', metered: '950', loggedUnits: 950);

        $assessment = app(LeakageDetector::class)->evaluateBatch($batch);

        $this->assertSame('970.000', $assessment->expectedLitres);
        $this->assertSame('950.000', $assessment->loggedLitres);
        $this->assertSame('2.061', $assessment->lossPercent);
        $this->assertSame('review', $assessment->lossSeverity);
        $this->assertStringContainsString('lost 5.0% of its intake against an expected 3.0% normal loss (2.1% beyond normal): 1,000 L intake, 970 L expected after normal loss, 950 L logged as 950 bottles.', $assessment->lossExplanation());

        app(SettingsService::class)->set('leakage.normal_process_loss_percent', 4.0, $this->operator);
        $this->assertNull(app(LeakageDetector::class)->evaluateBatch($batch)->lossSeverity, 'With 4% normal loss, 950 of 960 L is within tolerance.');
    }

    public function test_process_loss_just_below_review_threshold_raises_no_report(): void
    {
        $batch = $this->completedBatch(raw: '1000', metered: '951', loggedUnits: 951);

        $assessment = app(LeakageDetector::class)->evaluateBatch($batch);
        app(LeakageReporter::class)->recordProcess($batch, $this->product, $this->line->id, $assessment, null);

        $this->assertSame('1.958', $assessment->lossPercent);
        $this->assertDatabaseCount('leakage_reports', 0);
    }

    public function test_metered_bottled_volume_versus_logged_gap_is_its_own_finding(): void
    {
        $batch = $this->completedBatch(raw: '1000', metered: '980', loggedUnits: 965);

        $assessment = app(LeakageDetector::class)->evaluateBatch($batch);
        app(LeakageReporter::class)->recordProcess($batch, $this->product, $this->line->id, $assessment, null);

        $this->assertNull($assessment->lossSeverity, '965 of 970 expected litres is a 0.5% loss.');
        $this->assertSame('1.530', $assessment->gapPercent);
        $this->assertDatabaseCount('leakage_reports', 0);

        $bigGap = $this->completedBatch(raw: '1000', metered: '990', loggedUnits: 965);
        $gapAssessment = app(LeakageDetector::class)->evaluateBatch($bigGap);
        app(LeakageReporter::class)->recordProcess($bigGap, $this->product, $this->line->id, $gapAssessment, null);

        $report = LeakageReport::query()->sole();
        $this->assertSame('logging_gap', $report->finding);
        $this->assertSame('process', $report->source);
        $this->assertSame('review', $report->severity);
        $this->assertSame('2.525', $report->loss_percent);
        $this->assertStringContainsString('metered 990 L bottled but 965 L were logged as bottles (2.5% gap): bottles were filled but not logged', $report->explanation);
    }

    public function test_anomaly_check_is_skipped_with_fewer_than_ten_batches(): void
    {
        $this->historicalBatches(array_fill(0, 9, 0.5));

        $anomaly = app(LeakageDetector::class)->anomalyFor($this->product, $this->line->id, 1.9);

        $this->assertSame('skipped', $anomaly->status);
        $this->assertNull($anomaly->zScore);
        $this->assertSame('Anomaly check skipped: not enough history (9 of 10 completed batches).', $anomaly->sentence());
    }

    public function test_unusual_loss_below_the_thresholds_is_flagged_by_the_anomaly_check(): void
    {
        $this->historicalBatches([0.4, 0.5, 0.6, 0.5, 0.4, 0.6, 0.5, 0.7, 0.3, 0.5, 0.6, 0.4]);
        $batch = $this->completedBatch(raw: '1000', metered: '952', loggedUnits: 952);

        $assessment = app(LeakageDetector::class)->evaluateBatch($batch);
        app(LeakageReporter::class)->recordProcess($batch, $this->product, $this->line->id, $assessment, null);

        $this->assertNull($assessment->lossSeverity);
        $this->assertSame('unusual', $assessment->anomaly->status);
        $this->assertSame('product and line', $assessment->anomaly->scope);
        $report = LeakageReport::query()->sole();
        $this->assertSame('anomaly', $report->detected_by);
        $this->assertSame('review', $report->severity);
        $this->assertGreaterThanOrEqual(3.5, (float) $report->z_score);
        $this->assertSame('1.855', $assessment->lossPercent, '18 of 970 L, truncated to 3 decimals.');
        $this->assertSame(round(0.6745 * (1.855 - 0.5) / 0.1, 3), round((float) $report->z_score, 3));
        $this->assertStringContainsString('Unusual for this product and line', $report->explanation);
    }

    public function test_baseline_falls_back_to_the_product_when_the_line_has_too_little_history(): void
    {
        $otherLine = ProductionLine::query()->create(['name' => 'Line 02', 'status' => 'active']);
        $this->historicalBatches(array_fill(0, 10, 0.5), $otherLine);
        $this->historicalBatches([0.5, 0.6]);

        $anomaly = app(LeakageDetector::class)->anomalyFor($this->product, $this->line->id, 0.6);

        $this->assertSame('product', $anomaly->scope);
        $this->assertSame(12, $anomaly->baselineSize);
    }

    public function test_zero_mad_uses_the_mean_absolute_deviation_fallback(): void
    {
        $this->historicalBatches([...array_fill(0, 10, 1.0), 3.0]);

        $anomaly = app(LeakageDetector::class)->anomalyFor($this->product, $this->line->id, 2.0);

        $this->assertSame(0.0, $anomaly->mad);
        $meanAbsoluteDeviation = 2.0 / 11;
        $this->assertSame(round((2.0 - 1.0) / (1.253314 * $meanAbsoluteDeviation), 3), $anomaly->zScore);
        $this->assertSame('unusual', $anomaly->status);
    }

    public function test_identical_baseline_skips_the_anomaly_check(): void
    {
        $this->historicalBatches(array_fill(0, 12, 1.0));

        $anomaly = app(LeakageDetector::class)->anomalyFor($this->product, $this->line->id, 4.0);

        $this->assertSame('skipped', $anomaly->status);
        $this->assertStringContainsString('no spread', $anomaly->sentence());
    }

    public function test_completing_a_run_creates_its_batch_reports_and_notifies_once(): void
    {
        $inventoryStaff = User::factory()->create(['role_id' => Role::query()->where('slug', 'inventory-staff')->valueOrFail('id')]);
        $run = $this->activeRun(loggedUnits: 900);

        $assessment = app(ProductionRunCompletion::class)->complete($run, '1000', '940', $this->operator);

        $batch = ProductionBatch::query()->where('production_schedule_id', $run->id)->sole();
        $this->assertSame('completed', $batch->status);
        $this->assertSame('7.216', $assessment->lossPercent);
        $this->assertSame('7.216', (string) $batch->process_loss_percent);
        $this->assertSame(['logging_gap', 'process_loss'], LeakageReport::query()->orderBy('finding')->pluck('finding')->all());
        $this->assertSame('investigate', LeakageReport::query()->where('finding', 'process_loss')->value('severity'));
        $this->assertSame(2, $inventoryStaff->notifications()->count());

        // Re-evaluating the same batch updates the existing reports and stays quiet.
        app(LeakageReporter::class)->recordProcess($batch, $this->product, $this->line->id, app(LeakageDetector::class)->evaluateBatch($batch), $this->operator);

        $this->assertDatabaseCount('leakage_reports', 2);
        $this->assertSame(2, $inventoryStaff->notifications()->count());
    }

    public function test_leakage_notifications_respect_the_setting(): void
    {
        $administrator = User::factory()->create(['role_id' => Role::query()->where('slug', 'administrator')->valueOrFail('id')]);
        $inventoryStaff = User::factory()->create(['role_id' => Role::query()->where('slug', 'inventory-staff')->valueOrFail('id')]);
        app(SettingsService::class)->set('notify.leakage_flag', false, $administrator);

        app(ProductionRunCompletion::class)->complete($this->activeRun(loggedUnits: 900), '1000', '900', $this->operator);

        $this->assertDatabaseHas('leakage_reports', ['finding' => 'process_loss']);
        $this->assertSame(0, $inventoryStaff->notifications()->count());
    }

    private function activeRun(int $loggedUnits, ?ProductionLine $line = null): ProductionSchedule
    {
        $run = ProductionSchedule::query()->create([
            'product_sku' => $this->product->unit_type,
            'production_line_id' => ($line ?? $this->line)->id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => '08:00:00',
            'target_units' => 1000,
            'status' => 'active',
        ]);

        if ($loggedUnits > 0) {
            ProductionLog::query()->create([
                'production_schedule_id' => $run->id,
                'operator_id' => $this->operator->id,
                'units_logged' => $loggedUnits,
                'logged_at' => now(),
                'quality_status' => 'passed',
            ]);
        }

        return $run;
    }

    private function completedBatch(string $raw, string $metered, int $loggedUnits): ProductionBatch
    {
        $run = $this->activeRun($loggedUnits);
        $run->update(['status' => 'complete']);

        return ProductionBatch::query()->create([
            'user_id' => $this->operator->id,
            'production_schedule_id' => $run->id,
            'batch_date' => today()->toDateString(),
            'raw_water_volume' => $raw,
            'bottled_volume' => $metered,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<int, float>  $lossPercents
     */
    private function historicalBatches(array $lossPercents, ?ProductionLine $line = null): void
    {
        foreach ($lossPercents as $index => $lossPercent) {
            $run = $this->activeRun(0, $line);
            $run->update(['status' => 'complete']);
            ProductionBatch::query()->create([
                'user_id' => $this->operator->id,
                'production_schedule_id' => $run->id,
                'batch_date' => today()->subDays($index + 1)->toDateString(),
                'raw_water_volume' => 1000,
                'bottled_volume' => 960,
                'process_loss_percent' => $lossPercent,
                'status' => 'completed',
                'completed_at' => now()->subDays($index + 1),
            ]);
        }
    }
}
