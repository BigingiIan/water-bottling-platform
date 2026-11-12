<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the product catalogue a working install needs. Prices are each SKU's latest unit_price_kes in the
 * demand dataset (31 Dec 2025), held here so seeding does not depend on the CSV; volume comes from the SKU label.
 *
 * Only missing rows and missing values are filled: a price an operator has changed is never overwritten.
 */
class ProductCatalogSeeder extends Seeder
{
    /**
     * @var array<string, string> SKU => unit price in KES
     */
    public const CATALOGUE = [
        '500ml' => '25.00',
        '1L' => '45.00',
        '5L' => '120.00',
        '10L' => '220.00',
        '20L' => '350.00',
    ];

    public function run(): void
    {
        foreach (self::CATALOGUE as $sku => $price) {
            $product = Product::query()->firstOrNew(['unit_type' => $sku]);
            $product->name ??= "Bottled Water {$sku}";
            $product->unit_price_kes ??= $price;
            $product->volume_litres ??= self::volumeFromSku($sku);
            $product->save();
        }
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
}
