<?php

namespace App\Services\Orders;

/**
 * Exact KES arithmetic on decimal strings (bcmath, two decimal places). Money never passes through floats.
 */
final class Money
{
    private const SCALE = 2;

    public static function normalize(string $amount): string
    {
        return bcadd($amount, '0', self::SCALE);
    }

    /**
     * Rounds a SQL aggregate to two decimal places. MySQL returns exact decimal strings; SQLite returns floats,
     * which are fixed to six places before rounding so binary noise (8799.9999999) cannot leak into the result.
     */
    public static function fromAggregate(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return bcround(is_float($value) ? sprintf('%.6F', $value) : (string) $value, self::SCALE);
    }

    public static function multiply(int $quantity, string $unitPrice): string
    {
        return bcmul((string) $quantity, self::normalize($unitPrice), self::SCALE);
    }

    /**
     * @param  array<int, string>  $amounts
     */
    public static function sum(array $amounts): string
    {
        return array_reduce($amounts, fn (string $total, string $amount): string => bcadd($total, $amount, self::SCALE), '0.00');
    }

    public static function format(string $amount): string
    {
        [$whole, $fraction] = explode('.', self::normalize($amount));
        $negative = str_starts_with($whole, '-');
        $digits = ltrim($whole, '-');

        return ($negative ? '-' : '').'KES '.strrev(implode(',', str_split(strrev($digits), 3))).'.'.$fraction;
    }
}
