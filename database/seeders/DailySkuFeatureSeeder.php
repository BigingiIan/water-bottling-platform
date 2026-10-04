<?php

namespace Database\Seeders;

use App\Services\KenyanCalendarFeatures;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DailySkuFeatureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $calendarFeatures = app(KenyanCalendarFeatures::class);
        $skus = DB::table('products')
            ->whereNotNull('unit_type')
            ->distinct()
            ->pluck('unit_type');

        if ($skus->isEmpty()) {
            return;
        }

        $start = today()->subYears(2);
        $end = today()->addYear();
        $timestamp = now();

        foreach ($skus as $sku) {
            $rows = [];

            foreach (CarbonPeriod::create($start, $end) as $date) {
                $rows[] = [
                    'product_sku' => $sku,
                    'date' => $date->toDateString(),
                    'is_promotion' => null,
                    'is_public_holiday' => $calendarFeatures->isPublicHoliday($date),
                    'is_school_term' => $calendarFeatures->isSchoolTerm($date),
                    'temperature_c' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                if (count($rows) === 500) {
                    $this->upsert($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                $this->upsert($rows);
            }
        }
    }

    /**
     * Preserve manually supplied promotion and temperature values on reruns.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        DB::table('daily_sku_features')->upsert(
            $rows,
            ['product_sku', 'date'],
            ['is_public_holiday', 'is_school_term', 'updated_at'],
        );
    }
}
