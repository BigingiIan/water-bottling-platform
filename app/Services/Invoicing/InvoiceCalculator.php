<?php

namespace App\Services\Invoicing;

/**
 * All invoice VAT maths, in exact decimal strings. Lines are summed first and VAT is rounded once,
 * half away from zero to 2 decimal places, at invoice level.
 */
class InvoiceCalculator
{
    private const WORKING_SCALE = 10;

    /**
     * @param  array<int, string>  $lineTotals
     * @return array{subtotal: string, vatAmount: string, total: string}
     */
    public function totals(array $lineTotals, string $vatRate, bool $pricesIncludeVat): array
    {
        $linesSum = array_reduce($lineTotals, fn (string $sum, string $line): string => bcadd($sum, $line, 2), '0.00');
        $rate = bcadd($vatRate, '0', 4);

        if (bccomp($rate, '0', 4) === 0) {
            return ['subtotal' => $linesSum, 'vatAmount' => '0.00', 'total' => $linesSum];
        }

        if ($pricesIncludeVat) {
            // Line totals already contain VAT: total is the sum; VAT is extracted from it.
            $subtotal = bcround(bcdiv(bcmul($linesSum, '100', self::WORKING_SCALE), bcadd('100', $rate, self::WORKING_SCALE), self::WORKING_SCALE), 2);

            return ['subtotal' => $this->money($subtotal), 'vatAmount' => bcsub($linesSum, $subtotal, 2), 'total' => $linesSum];
        }

        $vat = bcround(bcdiv(bcmul($linesSum, $rate, self::WORKING_SCALE), '100', self::WORKING_SCALE), 2);

        return ['subtotal' => $linesSum, 'vatAmount' => $this->money($vat), 'total' => bcadd($linesSum, $vat, 2)];
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
