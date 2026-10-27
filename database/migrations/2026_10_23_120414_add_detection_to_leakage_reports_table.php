<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leakage_reports', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->change();
        });

        Schema::table('leakage_reports', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('batch_id')->constrained('products')->nullOnDelete();
            $table->foreignId('production_line_id')->nullable()->after('product_id')->constrained('production_lines')->nullOnDelete();
            $table->string('warehouse_location')->nullable()->after('production_line_id');
            $table->foreignId('stock_count_id')->nullable()->after('warehouse_location')->constrained('stock_counts')->nullOnDelete();
            $table->enum('source', ['manual', 'process', 'stock'])->default('manual')->after('report_date');
            $table->enum('finding', ['manual', 'process_loss', 'logging_gap', 'stock_shrinkage'])->default('manual')->after('source');
            $table->enum('detected_by', ['manual', 'rule', 'anomaly'])->default('manual')->after('finding');
            $table->enum('severity', ['review', 'investigate'])->nullable()->after('detected_by');
            $table->decimal('loss_percent', 8, 3)->nullable()->after('severity');
            $table->decimal('z_score', 8, 3)->nullable()->after('loss_percent');
            $table->text('explanation')->nullable()->after('z_score');
            $table->string('detection_key')->nullable()->unique()->after('explanation');
            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open')->after('detection_key');
            $table->foreignId('acknowledged_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable()->after('acknowledged_by');
            $table->text('resolution_note')->nullable()->after('acknowledged_at');
            $table->foreignId('resolved_by')->nullable()->after('resolution_note')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
            $table->timestamps();

            $table->index(['status', 'severity']);
        });

        $this->backfillManualReports();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leakage_reports', function (Blueprint $table) {
            $table->dropIndex(['status', 'severity']);
            $table->dropUnique(['detection_key']);
            $table->dropConstrainedForeignId('product_id');
            $table->dropConstrainedForeignId('production_line_id');
            $table->dropConstrainedForeignId('stock_count_id');
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn([
                'warehouse_location', 'source', 'finding', 'detected_by', 'severity', 'loss_percent', 'z_score',
                'explanation', 'detection_key', 'status', 'acknowledged_at', 'resolution_note', 'resolved_at',
                'created_at', 'updated_at',
            ]);
        });
    }

    /**
     * Existing hand-entered reports get the loss % and severity the current thresholds give them,
     * using the same boundaries as LeakageReportFlag (below review = none, up to investigate = review).
     */
    private function backfillManualReports(): void
    {
        $overrides = Schema::hasTable('settings')
            ? DB::table('settings')->whereIn('key', ['leakage.review_percent', 'leakage.investigate_percent'])->pluck('value', 'key')
            : collect();
        $definitions = (array) config('settings.definitions', []);
        $review = (float) ($overrides['leakage.review_percent'] ?? $definitions['leakage.review_percent']['default'] ?? 2.0);
        $investigate = (float) ($overrides['leakage.investigate_percent'] ?? $definitions['leakage.investigate_percent']['default'] ?? 5.0);

        DB::table('leakage_reports')->orderBy('id')->each(function (object $report) use ($review, $investigate): void {
            $expected = (float) $report->expected_volume;
            $lossPercent = $expected > 0 ? abs((float) $report->discrepancy) / $expected * 100 : 0.0;

            DB::table('leakage_reports')->where('id', $report->id)->update([
                'loss_percent' => round($lossPercent, 3),
                'severity' => match (true) {
                    $lossPercent < $review => null,
                    $lossPercent <= $investigate => 'review',
                    default => 'investigate',
                },
                'created_at' => $report->report_date,
                'updated_at' => $report->report_date,
            ]);
        });
    }
};
