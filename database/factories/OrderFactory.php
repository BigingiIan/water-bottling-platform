<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Orders\OrderStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'ORD-'.now()->year.'-'.fake()->unique()->numerify('9###'),
            'customer_id' => Customer::factory(),
            'status' => OrderStatus::Draft,
            'order_date' => today(),
            'requested_date' => today()->addDay(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Confirmed, 'confirmed_at' => now()]);
    }
}
