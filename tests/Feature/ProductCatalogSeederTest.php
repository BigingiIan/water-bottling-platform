<?php

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prices_each_sku_from_its_latest_csv_row_and_derives_volume(): void
    {
        Product::factory()->create(['unit_type' => '1L', 'name' => 'Existing 1L', 'unit_price_kes' => null]);

        app(ProductCatalogSeeder::class)->run();

        $products = Product::query()->get()->keyBy('unit_type');
        $this->assertSame($this->latestCsvPrices(), $products->map(fn (Product $product): string => $product->unit_price_kes)->sortKeys()->all());
        $this->assertSame('Existing 1L', $products['1L']->name);
        $this->assertSame('0.500', $products['500ml']->volume_litres);
        $this->assertSame('1.000', $products['1L']->volume_litres);
        $this->assertSame('20.000', $products['20L']->volume_litres);

        $products['5L']->update(['unit_price_kes' => '135.00']);
        app(ProductCatalogSeeder::class)->run();
        $this->assertSame(5, Product::query()->count());
        $this->assertSame('135.00', $products['5L']->refresh()->unit_price_kes, 'A price an operator changed is kept.');
    }

    /**
     * @return array<string, string>
     */
    private function latestCsvPrices(): array
    {
        $handle = fopen(base_path('data/kenya_water_demand_daily.csv'), 'r');
        $headers = fgetcsv($handle);
        $latest = [];

        while (($values = fgetcsv($handle)) !== false) {
            $row = array_combine($headers, $values);

            if (! isset($latest[$row['product_sku']]) || $row['date'] >= $latest[$row['product_sku']]['date']) {
                $latest[$row['product_sku']] = ['date' => $row['date'], 'price' => number_format((float) $row['unit_price_kes'], 2, '.', '')];
            }
        }

        fclose($handle);
        ksort($latest);

        return array_map(fn (array $row): string => $row['price'], $latest);
    }
}
