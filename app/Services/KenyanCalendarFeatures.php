<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class KenyanCalendarFeatures
{
    /**
     * Seed statutory fixed-date holidays and Easter's observed Friday and Monday.
     */
    public function isPublicHoliday(Carbon $date): bool
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

    public function isSchoolTerm(Carbon $date): bool
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
}
