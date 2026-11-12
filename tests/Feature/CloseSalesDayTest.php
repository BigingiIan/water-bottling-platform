<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DailySkuFeature;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\SchoolTerm;
use App\Services\Orders\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CloseSalesDayTest extends TestCase
{
    use RefreshDatabase;

    private string $modelDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        // 01:00 UTC is 04:00 in Africa/Nairobi, so "yesterday" is 21 Oct in both.
        $this->travelTo('2026-10-22 01:00:00');
        $this->modelDirectory = storage_path('framework/testing/close-day-manifests');
        config(['services.ml_forecast.model_dir' => $this->modelDirectory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->modelDirectory);

        parent::tearDown();
    }

    public function test_day_is_covered_when_no_order_dated_that_day_is_still_open(): void
    {
        $product = $this->product('1L');
        $this->orderOn('2026-10-21', OrderStatus::Delivered, $product);
        $this->orderOn('2026-10-21', OrderStatus::Cancelled, $product);
        $this->orderOn('2026-10-22', OrderStatus::Draft, $product);
        SchoolTerm::factory()->create(['year' => 2026, 'term' => 3, 'start_date' => '2026-08-25', 'end_date' => '2026-10-31']);

        $this->artisan('sales:close-day')
            ->expectsOutput('Closed 1 of 1 products for 2026-10-21.')
            ->assertSuccessful();

        $feature = DailySkuFeature::query()->where('product_sku', '1L')->whereDate('date', '2026-10-21')->sole();
        $this->assertTrue($feature->sales_data_covered);
        $this->assertFalse((bool) $feature->is_promotion);
        $this->assertFalse((bool) $feature->is_public_holiday);
        $this->assertTrue((bool) $feature->is_school_term);
        $this->assertSame(21.0, (float) $feature->temperature_c);
        $this->assertSame('monthly_mean', $feature->temperature_source);
    }

    public function test_open_orders_keep_their_products_uncovered_and_are_reported(): void
    {
        $oneLitre = $this->product('1L');
        $fiveLitre = $this->product('5L');
        $draft = $this->orderOn('2026-10-21', OrderStatus::Draft, $oneLitre);
        $confirmed = $this->orderOn('2026-10-21', OrderStatus::Confirmed, $oneLitre);
        Promotion::factory()->create(['product_id' => $oneLitre->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-22']);

        $expectedOrders = collect(["{$draft->order_number} (draft)", "{$confirmed->order_number} (confirmed)"])->sort()->implode(', ');

        $this->artisan('sales:close-day', ['date' => '2026-10-21'])
            ->expectsOutputToContain("2 open orders: {$expectedOrders}")
            ->expectsOutput('Closed 1 of 2 products for 2026-10-21.')
            ->assertSuccessful();

        $oneLitreFeature = DailySkuFeature::query()->where('product_sku', '1L')->whereDate('date', '2026-10-21')->sole();
        $this->assertFalse($oneLitreFeature->sales_data_covered);
        $this->assertTrue((bool) $oneLitreFeature->is_promotion, 'The 1L promotion in the calendar is recorded.');
        $fiveLitreFeature = DailySkuFeature::query()->where('product_sku', '5L')->whereDate('date', '2026-10-21')->sole();
        $this->assertTrue($fiveLitreFeature->sales_data_covered);
        $this->assertFalse((bool) $fiveLitreFeature->is_promotion, 'A product-specific promotion does not flag other products.');
    }

    public function test_products_without_climatology_are_reported_and_not_closed(): void
    {
        $this->product('1L');
        Product::factory()->create(['unit_type' => '20L', 'name' => 'Bottled Water 20L']);

        $this->artisan('sales:close-day', ['date' => '2026-10-21'])
            ->expectsOutputToContain('Model manifest not found for 20L')
            ->expectsOutput('Closed 1 of 2 products for 2026-10-21.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('daily_sku_features', ['product_sku' => '20L']);
    }

    public function test_catch_up_closes_an_earlier_day_once_its_late_order_is_delivered(): void
    {
        $product = $this->product('1L');
        $lateOrder = $this->orderOn('2026-10-15', OrderStatus::Confirmed, $product);
        $this->artisan('sales:close-day', ['date' => '2026-10-15'])->assertSuccessful();
        $this->assertFalse(DailySkuFeature::query()->whereDate('date', '2026-10-15')->sole()->sales_data_covered);

        $lateOrder->update(['status' => OrderStatus::Delivered]);
        $this->artisan('sales:close-day', ['date' => '2026-10-21', '--catch-up' => true])
            ->expectsOutput('Closed 1 of 1 products for 2026-10-21.')
            ->assertSuccessful();
        $this->artisan('sales:close-day', ['date' => '2026-10-21', '--catch-up' => true])->assertSuccessful();

        $this->assertTrue(DailySkuFeature::query()->whereDate('date', '2026-10-15')->sole()->sales_data_covered);
        $this->assertSame(7, DailySkuFeature::query()->where('sales_data_covered', true)->count(), 'Days before the first order stay unclosed and reruns add no rows.');
    }

    public function test_invalid_dates_are_rejected(): void
    {
        $this->artisan('sales:close-day', ['date' => '2026-02-30'])
            ->expectsOutput('2026-02-30 is not a valid Y-m-d date.')
            ->assertExitCode(2);
    }

    private function product(string $sku): Product
    {
        File::ensureDirectoryExists("{$this->modelDirectory}/{$sku}");
        File::put("{$this->modelDirectory}/{$sku}/manifest.json", json_encode([
            'temperature_climatology' => array_combine(array_map('strval', range(1, 12)), array_map(fn (int $month): float => 20 + $month / 10, range(1, 12))),
        ]));

        return Product::factory()->create(['unit_type' => $sku, 'name' => "Bottled Water {$sku}"]);
    }

    private function orderOn(string $date, OrderStatus $status, Product $product): Order
    {
        $order = Order::factory()->create([
            'customer_id' => Customer::factory(),
            'status' => $status,
            'order_date' => $date,
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 5]);

        return $order;
    }
}
