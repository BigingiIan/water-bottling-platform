<?php

namespace Tests\Feature;

use App\Models\DailySkuFeature;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Database\Seeders\DailySkuFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailySkuFeatureSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00'));
    }

    public function test_it_seeds_known_kenyan_holidays_and_term_periods_without_fabricating_open_features(): void
    {
        Product::create(['name' => 'Bottled Water', 'unit_type' => '1L']);

        app(DailySkuFeatureSeeder::class)->run();

        $mashujaaDay = DailySkuFeature::query()
            ->where('product_sku', '1L')
            ->whereDate('date', '2026-10-20')
            ->firstOrFail();
        $goodFriday = DailySkuFeature::query()
            ->where('product_sku', '1L')
            ->whereDate('date', '2026-04-03')
            ->firstOrFail();
        $schoolHoliday = DailySkuFeature::query()
            ->where('product_sku', '1L')
            ->whereDate('date', '2026-12-01')
            ->firstOrFail();

        $this->assertTrue($mashujaaDay->is_public_holiday);
        $this->assertTrue($mashujaaDay->is_school_term);
        $this->assertTrue($goodFriday->is_public_holiday);
        $this->assertFalse($schoolHoliday->is_school_term);
        $this->assertNull($mashujaaDay->is_promotion);
        $this->assertNull($mashujaaDay->temperature_c);
    }

    public function test_rerunning_the_seeder_preserves_manual_promotion_and_temperature_values(): void
    {
        Product::create(['name' => 'Bottled Water', 'unit_type' => '1L']);
        $seeder = app(DailySkuFeatureSeeder::class);
        $seeder->run();

        DailySkuFeature::query()
            ->where('product_sku', '1L')
            ->whereDate('date', '2026-10-04')
            ->update([
                'is_promotion' => true,
                'temperature_c' => 23.5,
            ]);

        $seeder->run();

        $feature = DailySkuFeature::query()
            ->where('product_sku', '1L')
            ->whereDate('date', '2026-10-04')
            ->firstOrFail();

        $this->assertTrue($feature->is_promotion);
        $this->assertSame(23.5, $feature->temperature_c);
    }
}
