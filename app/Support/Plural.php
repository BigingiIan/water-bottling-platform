<?php

namespace App\Support;

use Illuminate\Support\Str;

class Plural
{
    /**
     * Formats a count with its noun in the matching number, e.g. "1 job", "2 jobs", "1,250 units".
     */
    public static function count(int|float $count, string $noun): string
    {
        $decimals = is_float($count) && floor($count) !== $count ? 1 : 0;

        return number_format($count, $decimals).' '.Str::plural($noun, abs($count) == 1 ? 1 : 2);
    }
}
