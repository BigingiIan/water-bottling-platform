<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Physical stock counts with the movement-derived expectation they were compared against.
     */
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('warehouse_location')->nullable();
            $table->integer('counted_units');
            $table->integer('opening_units');
            $table->integer('production_in')->default(0);
            $table->integer('sales_out')->default(0);
            $table->integer('adjustments_net')->default(0);
            $table->integer('expected_units');
            $table->integer('system_units');
            $table->integer('shrinkage_units');
            $table->foreignId('previous_count_id')->nullable()->constrained('stock_counts')->nullOnDelete();
            // Highest stock_movements.id included in this count (after its own correction), so the next
            // count sums exactly the movements that came later.
            $table->unsignedBigInteger('last_movement_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'warehouse_location', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_counts');
    }
};
