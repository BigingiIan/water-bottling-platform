<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leakage_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('production_batches')->cascadeOnDelete();
            $table->decimal('expected_volume', 12, 2);
            $table->decimal('actual_volume', 12, 2);
            $table->decimal('discrepancy', 12, 2);
            $table->date('report_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leakage_reports');
    }
};
