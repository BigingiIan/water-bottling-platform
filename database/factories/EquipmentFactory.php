<?php

namespace Database\Factories;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    /**
     * Define the model's default state: operational equipment not tied to a line.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'EQ-'.fake()->unique()->numerify('###'),
            'name' => ucfirst(fake()->words(2, true)),
            'type' => 'filler',
            'production_line_id' => null,
            'location' => 'Bottling hall',
            'manufacturer' => null,
            'model' => null,
            'serial_number' => null,
            'installed_on' => null,
            'notes' => null,
        ];
    }

    /**
     * Status is not fillable (MaintenanceService owns changes), so tests set it here.
     */
    public function status(string $status): static
    {
        return $this->afterMaking(function (Equipment $equipment) use ($status): void {
            $equipment->status = $status;
        });
    }
}
