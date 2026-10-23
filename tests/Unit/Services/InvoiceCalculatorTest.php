<?php

namespace Tests\Unit\Services;

use App\Services\Invoicing\InvoiceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceCalculatorTest extends TestCase
{
    /**
     * @return array<string, array{array<int, string>, string, bool, array{subtotal: string, vatAmount: string, total: string}}>
     */
    public static function cases(): array
    {
        return [
            'exclusive, exact' => [['1000.00'], '16', false, ['subtotal' => '1000.00', 'vatAmount' => '160.00', 'total' => '1160.00']],
            'exclusive, rounds half up' => [['1234.56'], '16', false, ['subtotal' => '1234.56', 'vatAmount' => '197.53', 'total' => '1432.09']],
            'exclusive, half cent rounds up' => [['0.05'], '16', false, ['subtotal' => '0.05', 'vatAmount' => '0.01', 'total' => '0.06']],
            'exclusive, lines summed before rounding' => [['0.03', '0.03', '0.03'], '16', false, ['subtotal' => '0.09', 'vatAmount' => '0.01', 'total' => '0.10']],
            'inclusive, exact' => [['1160.00'], '16', true, ['subtotal' => '1000.00', 'vatAmount' => '160.00', 'total' => '1160.00']],
            'inclusive, rounded split' => [['100.00'], '16', true, ['subtotal' => '86.21', 'vatAmount' => '13.79', 'total' => '100.00']],
            'inclusive, several lines' => [['45.00', '220.00', '25.00'], '16', true, ['subtotal' => '250.00', 'vatAmount' => '40.00', 'total' => '290.00']],
            'zero rate exclusive' => [['500.00'], '0', false, ['subtotal' => '500.00', 'vatAmount' => '0.00', 'total' => '500.00']],
            'zero rate inclusive' => [['500.00'], '0.00', true, ['subtotal' => '500.00', 'vatAmount' => '0.00', 'total' => '500.00']],
            'fractional rate' => [['200.00'], '12.5', false, ['subtotal' => '200.00', 'vatAmount' => '25.00', 'total' => '225.00']],
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array{subtotal: string, vatAmount: string, total: string}  $expected
     */
    #[DataProvider('cases')]
    public function test_vat_maths_rounds_once_to_two_decimals(array $lines, string $rate, bool $inclusive, array $expected): void
    {
        $totals = (new InvoiceCalculator)->totals($lines, $rate, $inclusive);

        $this->assertSame($expected, $totals);
        $this->assertSame(0, bccomp(bcadd($totals['subtotal'], $totals['vatAmount'], 2), $totals['total'], 2), 'Subtotal plus VAT always equals the total.');
    }
}
