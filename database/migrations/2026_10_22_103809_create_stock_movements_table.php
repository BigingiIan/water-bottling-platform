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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_stock_id')->constrained('warehouse_stock');
            $table->foreignId('product_id')->constrained('products');
            $table->enum('type', [
                'production',
                'sale',
                'adjustment',
                'leakage_writeoff',
                'return',
                'stock_in',
            ]);
            $table->integer('quantity_change');
            $table->integer('level_after');
            $table->string('reference')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['warehouse_stock_id', 'created_at']);
            $table->index(['product_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
