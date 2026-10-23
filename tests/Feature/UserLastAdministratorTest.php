<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserLastAdministratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_last_active_administrator_cannot_be_demoted(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $customRole = Role::query()->create([
            'name' => 'Quality Lead',
            'slug' => 'quality-lead',
        ]);
        $user = User::factory()->create([
            'role_id' => $administrator->id,
            'status' => 'active',
        ]);

        $this->assertRejectedAdministratorChange($user, [
            'role_id' => $customRole->id,
        ], 'role_id');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role_id' => $administrator->id,
            'status' => 'active',
        ]);
    }

    public function test_last_active_administrator_cannot_be_suspended(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $administrator->id,
            'status' => 'active',
        ]);

        $this->assertRejectedAdministratorChange($user, ['status' => 'suspended'], 'status');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role_id' => $administrator->id,
            'status' => 'active',
        ]);
    }

    public function test_administrator_can_be_demoted_when_another_active_administrator_remains(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $customRole = Role::query()->create([
            'name' => 'Quality Lead',
            'slug' => 'quality-lead',
        ]);
        $user = User::factory()->create([
            'role_id' => $administrator->id,
            'status' => 'active',
        ]);
        User::factory()->create([
            'role_id' => $administrator->id,
            'status' => 'active',
        ]);

        $user->update(['role_id' => $customRole->id]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role_id' => $customRole->id,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertRejectedAdministratorChange(User $user, array $attributes, string $errorKey): void
    {
        try {
            $user->update($attributes);
            $this->fail('The last active Administrator change should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
    }
}
