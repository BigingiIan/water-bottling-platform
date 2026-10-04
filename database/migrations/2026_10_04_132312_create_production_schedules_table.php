<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('production_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('product_sku');
            $table->foreignId('production_line_id')->constrained('production_lines')->restrictOnDelete();
            $table->date('scheduled_date');
            $table->time('scheduled_time');
            $table->unsignedInteger('target_units');
            $table->enum('status', ['queued', 'active', 'complete'])->default('queued');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_schedules');
    }
};
