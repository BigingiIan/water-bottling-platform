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
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // A recurring holiday matches this month and day in every year; a one-off matches this date only.
            $table->date('date');
            $table->boolean('recurring')->default(false);
            $table->timestamps();

            $table->index(['recurring', 'date']);
        });

        Schema::create('school_terms', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('term');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->unique(['year', 'term']);
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            // Null applies the promotion to every product.
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('type', ['price_discount', 'bundle', 'display', 'other']);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });

        Schema::table('daily_sku_features', function (Blueprint $table) {
            $table->string('temperature_source', 20)->nullable()->after('temperature_c');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_sku_features', function (Blueprint $table) {
            $table->dropColumn('temperature_source');
        });

        Schema::dropIfExists('promotions');
        Schema::dropIfExists('school_terms');
        Schema::dropIfExists('public_holidays');
    }
};
