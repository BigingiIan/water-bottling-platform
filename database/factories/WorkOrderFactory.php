<?php

namespace Database\Factories;

use App\Models\Equipment;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    /**
     * Define the model's default state: an open corrective work order.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'WO-'.now()->year.'-'.fake()->unique()->numerify('9###'),
            'equipment_id' => Equipment::factory(),
            'type' => 'corrective',
            'source' => 'breakdown',
            'priority' => 'normal',
            'description' => fake()->sentence(),
        ];
    }

    /**
     * A finished work order with its downtime, for downtime sums.
     */
    public function done(int $downtimeMinutes, ?string $completedAt = null): static
    {
        return $this->afterMaking(function (WorkOrder $workOrder) use ($downtimeMinutes, $completedAt): void {
            $workOrder->status = 'done';
            $workOrder->downtime_minutes = $downtimeMinutes;
            $workOrder->started_at = $completedAt ?? now();
            $workOrder->completed_at = $completedAt ?? now();
        });
    }
}
