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
        Schema::table('daily_sku_features', function (Blueprint $table) {
            $table->boolean('sales_data_covered')->default(false)->after('is_school_term');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_sku_features', function (Blueprint $table) {
            $table->dropColumn('sales_data_covered');
        });
    }
};
