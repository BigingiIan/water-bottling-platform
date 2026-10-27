<?php

namespace Database\Factories;

use App\Models\DepartmentRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Requests\RequestPriority;
use App\Services\Requests\RequestStatus;
use App\Services\Requests\RequestType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentRequest>
 */
class DepartmentRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'REQ-'.now()->year.'-'.fake()->unique()->numerify('9###'),
            'type' => RequestType::General,
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'priority' => RequestPriority::Normal,
            'from_user_id' => User::factory(),
            'to_role_id' => fn (): int => Role::query()->value('id') ?? Role::query()->create(['name' => 'Team '.fake()->unique()->word(), 'slug' => fake()->unique()->slug(2)])->id,
            'due_date' => today()->addDays(2),
        ];
    }

    public function status(RequestStatus $status): static
    {
        return $this->afterMaking(function (DepartmentRequest $request) use ($status): void {
            $request->status = $status;
        });
    }
}
