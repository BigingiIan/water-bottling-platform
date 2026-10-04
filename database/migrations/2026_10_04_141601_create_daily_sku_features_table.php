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
        Schema::create('daily_sku_features', function (Blueprint $table) {
            $table->id();
            $table->string('product_sku');
            $table->date('date');
            $table->boolean('is_promotion')->nullable();
            $table->boolean('is_public_holiday')->default(false);
            $table->boolean('is_school_term')->default(false);
            $table->decimal('temperature_c', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['product_sku', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_sku_features');
    }
};
