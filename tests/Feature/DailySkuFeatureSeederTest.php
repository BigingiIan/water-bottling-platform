<?php

namespace Tests\Feature;

use App\Models\DailySkuFeature;
use App\Models\Product;
use App\Models\SchoolTerm;
use Carbon\CarbonImmutable;
use Database\Seeders\DailySkuFeatureSeeder;
use Database\Seeders\PublicHolidaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailySkuFeatureSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00'));
        // No bundles here, so the seeder can't fill temperatures.
        config(['services.ml_forecast.model_dir' => storage_path('framework/testing/no-manifests')]);
    }

    public function test_it_writes_calendar_flags_from_the_business_calendar_without_guessing_movable_holidays(): void
    {
        Product::create(['name' => 'Bottled Water', 'unit_type' => '1L']);
        app(PublicHolidaySeeder::class)->run();
        SchoolTerm::factory()->create(['year' => 2026, 'term' => 3, 'start_date' => '2026-08-25', 'end_date' => '2026-10-30']);

        app(DailySkuFeatureSeeder::class)->run();

        $feature = fn (string $date): DailySkuFeature => DailySkuFeature::query()->where('product_sku', '1L')->whereDate('date', $date)->firstOrFail();

        $this->assertTrue($feature('2026-10-20')->is_public_holiday, 'Mashujaa Day is a fixed-date holiday.');
        $this->assertTrue($feature('2025-12-25')->is_public_holiday, 'Recurring holidays apply in every year.');
        $this->assertFalse($feature('2026-04-03')->is_public_holiday, 'Good Friday is movable and is not guessed.');
        $this->assertTrue($feature('2026-10-20')->is_school_term);
        $this->assertFalse($feature('2026-12-01')->is_school_term);
        $this->assertFalse($feature('2026-10-20')->is_promotion);
        $this->assertNull($feature('2026-10-20')->temperature_c);
    }

    public function test_rerunning_the_seeder_leaves_covered_history_untouched(): void
    {
        Product::create(['name' => 'Bottled Water', 'unit_type' => '1L']);
        $seeder = app(DailySkuFeatureSeeder::class);
        $seeder->run();

        DailySkuFeature::query()
            ->where('product_sku', '1L')
            ->whereDate('date', '2026-10-01')
            ->update(['is_promotion' => true, 'temperature_c' => 23.5, 'sales_data_covered' => true]);

        $seeder->run();

        $feature = DailySkuFeature::query()->where('product_sku', '1L')->whereDate('date', '2026-10-01')->firstOrFail();

        $this->assertTrue($feature->is_promotion);
        $this->assertSame(23.5, $feature->temperature_c);
    }
}
