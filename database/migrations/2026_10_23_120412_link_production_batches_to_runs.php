<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One production run produces one batch, so process-loss detection can reach the run's logs, product and line.
     */
    public function up(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->foreignId('production_schedule_id')->nullable()->unique()->after('user_id')
                ->constrained('production_schedules')->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->after('status');
            $table->decimal('logged_volume', 12, 3)->nullable()->after('bottled_volume');
            $table->decimal('process_loss_percent', 8, 3)->nullable()->after('logged_volume');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_schedule_id');
            $table->dropColumn(['completed_at', 'logged_volume', 'process_loss_percent']);
        });
    }
};
