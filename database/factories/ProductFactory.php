<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sku = fake()->unique()->numerify('##L');

        return [
            'name' => "Bottled Water {$sku}",
            'unit_type' => $sku,
            'reorder_threshold' => 100,
            'unit_price_kes' => '45.00',
            'volume_litres' => '1.000',
        ];
    }
}
