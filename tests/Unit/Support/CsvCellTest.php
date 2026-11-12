<?php

namespace Tests\Unit\Support;

use App\Services\Orders\Money;
use App\Support\CsvCell;
use PHPUnit\Framework\TestCase;

class CsvCellTest extends TestCase
{
    public function test_formula_text_is_quoted_and_numbers_are_left_alone(): void
    {
        $this->assertSame(
            ["'=HYPERLINK(\"http://x\")", "'+cmd", "'-2+3", "'@SUM(A1)", "'\tx", "'\rx", 'Acme Ltd', '-120.50', -5, null],
            CsvCell::escapeRow(['=HYPERLINK("http://x")', '+cmd', '-2+3', '@SUM(A1)', "\tx", "\rx", 'Acme Ltd', '-120.50', -5, null]),
        );
    }

    public function test_money_formats_in_kes_when_settings_are_unavailable(): void
    {
        $this->assertSame('-KES 1,234.50', Money::format('-1234.5'));
    }
}
