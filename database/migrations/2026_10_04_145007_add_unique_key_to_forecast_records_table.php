<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('forecast_records')
            ->select('product_id', 'forecast_date', 'model_version')
            ->groupBy('product_id', 'forecast_date', 'model_version')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'Duplicate forecast_records exist for the same product, date, and model version. Resolve duplicates before applying this migration.'
            );
        }

        Schema::table('forecast_records', function (Blueprint $table) {
            $table->unique(
                ['product_id', 'forecast_date', 'model_version'],
                'forecast_records_product_date_model_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('forecast_records', function (Blueprint $table) {
            $table->dropUnique('forecast_records_product_date_model_unique');
        });
    }
};
