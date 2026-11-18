<?php

namespace Database\Factories;

use App\Models\Equipment;
use App\Models\MaintenancePlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenancePlan>
 */
class MaintenancePlanFactory extends Factory
{
    /**
     * Define the model's default state: a weekly task first due today.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'equipment_id' => Equipment::factory(),
            'task' => 'Sanitise filler nozzles',
            'every_days' => 7,
            'first_due_on' => today()->toDateString(),
            'last_done_on' => null,
            'assigned_role_id' => null,
            'checklist' => ['Drain the line', 'Apply sanitiser', 'Rinse and check flow'],
            'active' => true,
        ];
    }
}
