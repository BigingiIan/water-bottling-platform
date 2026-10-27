<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use RuntimeException;
use SplFileObject;

/**
 * Prices every SKU at its most recent unit_price_kes in the demand CSV and derives volume from the SKU label.
 */
class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->latestPrices() as $sku => $price) {
            $product = Product::query()->firstOrNew(['unit_type' => $sku]);
            $product->name ??= "Bottled Water {$sku}";
            $product->unit_price_kes = $price;
            $product->volume_litres = self::volumeFromSku($sku);
            $product->save();
        }

        $this->command?->info('Priced products from the latest demand CSV rows.');
    }

    /**
     * "500ml" → "0.500", "20L" → "20.000".
     */
    public static function volumeFromSku(string $sku): string
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(ml|l)$/i', $sku, $matches) !== 1) {
            throw new RuntimeException("Cannot derive a volume from the SKU label {$sku}.");
        }

        $litres = strtolower($matches[2]) === 'ml'
            ? bcdiv($matches[1], '1000', 3)
            : bcadd($matches[1], '0', 3);

        return $litres;
    }

    /**
     * @return array<string, string> SKU => price with two decimals, from each SKU's latest dated row
     */
    private function latestPrices(): array
    {
        $path = base_path('data/kenya_water_demand_daily.csv');

        if (! is_readable($path)) {
            throw new RuntimeException("Demand CSV is missing or unreadable: {$path}");
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
        $columns = array_flip((array) $file->fgetcsv());
        $latest = [];

        foreach ($file as $values) {
            if (! is_array($values) || ! isset($values[$columns['unit_price_kes']]) || $values[$columns['date']] === 'date') {
                continue;
            }

            $sku = $values[$columns['product_sku']];
            $date = $values[$columns['date']];

            if (! isset($latest[$sku]) || $date >= $latest[$sku]['date']) {
                $latest[$sku] = ['date' => $date, 'price' => bcadd((string) $values[$columns['unit_price_kes']], '0', 2)];
            }
        }

        return array_map(fn (array $row): string => $row['price'], $latest);
    }
}
