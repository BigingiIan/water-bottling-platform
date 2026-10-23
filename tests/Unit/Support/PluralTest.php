<?php

namespace Tests\Unit\Support;

use App\Support\Plural;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PluralTest extends TestCase
{
    #[DataProvider('counts')]
    public function test_count_uses_the_noun_form_matching_the_number(int|float $count, string $noun, string $expected): void
    {
        $this->assertSame($expected, Plural::count($count, $noun));
    }

    /**
     * @return array<string, array{int|float, string, string}>
     */
    public static function counts(): array
    {
        return [
            'zero is plural' => [0, 'job', '0 jobs'],
            'one is singular' => [1, 'job', '1 job'],
            'two is plural' => [2, 'job', '2 jobs'],
            'multi-word noun' => [1, 'open run', '1 open run'],
            'irregular plural' => [3, 'log entry', '3 log entries'],
            'thousands are formatted' => [1250, 'unit', '1,250 units'],
            'whole float is singular' => [1.0, 'unit', '1 unit'],
            'fractional float keeps a decimal' => [2.5, 'day', '2.5 days'],
        ];
    }
}
