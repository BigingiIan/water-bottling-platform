<?php

namespace Database\Seeders;

use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailySkuFeatureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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
                    'is_public_holiday' => $this->isKenyanPublicHoliday($date),
                    'is_school_term' => $this->isSchoolTerm($date),
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
     * Seed statutory fixed-date holidays and Easter's observed Friday and Monday.
     */
    private function isKenyanPublicHoliday(Carbon $date): bool
    {
        $fixedHolidays = [
            '01-01',
            '05-01',
            '06-01',
            '10-10',
            '10-20',
            '12-12',
            '12-25',
            '12-26',
        ];

        if (in_array($date->format('m-d'), $fixedHolidays, true)) {
            return true;
        }

        $easterSunday = $this->easterSunday($date->year);

        return $date->isSameDay($easterSunday->copy()->subDays(2))
            || $date->isSameDay($easterSunday->copy()->addDay());
    }

    private function isSchoolTerm(Carbon $date): bool
    {
        return in_array($date->month, [1, 2, 3, 5, 6, 7, 9, 10, 11], true);
    }

    private function easterSunday(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::createFromDate($year, $month, $day)->startOfDay();
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
