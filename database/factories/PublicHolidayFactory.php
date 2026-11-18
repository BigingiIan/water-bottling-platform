<?php

namespace Database\Factories;

use App\Models\PublicHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicHoliday>
 */
class PublicHolidayFactory extends Factory
{
    /**
     * Define the model's default state: a one-off holiday.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' Day',
            'date' => today()->addDays(fake()->numberBetween(1, 60))->toDateString(),
            'recurring' => false,
        ];
    }

    public function recurring(string $monthDay): static
    {
        return $this->state(fn (): array => [
            'date' => PublicHoliday::RECURRING_YEAR.'-'.$monthDay,
            'recurring' => true,
        ]);
    }
}
