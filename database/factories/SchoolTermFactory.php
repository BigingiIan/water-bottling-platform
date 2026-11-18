<?php

namespace Database\Factories;

use App\Models\SchoolTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolTerm>
 */
class SchoolTermFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = (int) today()->format('Y');

        return [
            'year' => $year,
            'term' => 3,
            'start_date' => "{$year}-08-25",
            'end_date' => "{$year}-10-31",
        ];
    }
}
