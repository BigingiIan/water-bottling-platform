<?php

namespace Tests\Unit\Support;

use App\Support\LeakageReportFlag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeakageReportFlagTest extends TestCase
{
    #[DataProvider('flagCases')]
    public function test_flag_matches_the_discrepancy_thresholds(float $actual, string $expectedFlag): void
    {
        $this->assertSame($expectedFlag, LeakageReportFlag::fromVolumes(100, $actual));
    }

    public function test_zero_expected_volume_is_normal(): void
    {
        $this->assertSame('Normal', LeakageReportFlag::fromVolumes(0, 50));
    }

    public static function flagCases(): array
    {
        return [
            'below review threshold' => [98.01, 'Normal'],
            'exactly review threshold' => [98, 'Review'],
            'exactly investigate threshold' => [95, 'Review'],
            'above investigate threshold' => [94.99, 'Investigate'],
        ];
    }
}
