<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contact_number' => '+2547'.fake()->numerify('########'),
            'address' => fake()->city(),
            'customer_type' => fake()->randomElement(['retail', 'distributor', 'office']),
            'payment_terms_days' => 30,
        ];
    }
}
