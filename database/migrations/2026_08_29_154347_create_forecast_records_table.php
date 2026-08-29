<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->date('forecast_date');
            $table->decimal('predicted_demand', 12, 2);
            $table->integer('horizon_days');
            $table->string('model_version');
            $table->decimal('rmse_score', 8, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_records');
    }
};
