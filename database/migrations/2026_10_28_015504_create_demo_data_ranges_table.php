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
        // Which rows php artisan demo:seed wrote, so demo:clear can remove exactly those. Demo rows get
        // explicit, consecutive ids, so one id range per table identifies them.
        Schema::create('demo_data_ranges', function (Blueprint $table) {
            $table->string('table_name', 64)->primary();
            $table->unsignedBigInteger('first_id')->nullable();
            $table->unsignedBigInteger('last_id')->nullable();
            // For tables keyed by date rather than id (daily_sku_features): the demo period.
            $table->date('first_date')->nullable();
            $table->date('last_date')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demo_data_ranges');
    }
};
