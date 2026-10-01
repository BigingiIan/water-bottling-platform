<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            ModuleSeeder::class,
            RoleModulePermissionSeeder::class,
        ]);
    }

    public function test_administrator_sees_view_as_dropdown_with_active_users(): void
    {
        $administrator = $this->userWithRole('administrator');
        $this->userWithRole('production-staff', ['name' => 'Production Tester']);
        $this->userWithRole('inventory-staff', [
            'name' => 'Suspended Tester',
            'status' => 'suspended',
        ]);

        $this->actingAs($administrator)->get('/dashboard')
            ->assertSee('View As')
            ->assertSee('Production Tester')
            ->assertDontSee('Suspended Tester');
    }

    public function test_non_administrator_does_not_see_view_as_dropdown(): void
    {
        $productionStaff = $this->userWithRole('production-staff');

        $this->actingAs($productionStaff)->get('/dashboard')
            ->assertDontSee('View As')
            ->assertDontSee('view-as-user');
    }

    public function test_administrator_can_assume_and_return_from_an_active_user_session(): void
    {
        $administrator = $this->userWithRole('administrator');
        $targetUser = $this->userWithRole('production-staff', ['name' => 'Production Tester']);

        $this->actingAs($administrator)
            ->post(route('impersonation.store'), ['user_id' => $targetUser->id])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($targetUser);
        $this->assertSame($administrator->id, session('impersonator_id'));

        $this->get('/dashboard')
            ->assertSee('View As is active')
            ->assertSee('Production Tester')
            ->assertSee('Return to Admin');

        $this->post(route('impersonation.destroy'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($administrator);
        $this->assertFalse(session()->has('impersonator_id'));
    }

    public function test_non_administrator_cannot_start_impersonation(): void
    {
        $productionStaff = $this->userWithRole('production-staff');
        $targetUser = $this->userWithRole('inventory-staff');

        $this->actingAs($productionStaff)
            ->post(route('impersonation.store'), ['user_id' => $targetUser->id])
            ->assertForbidden();

        $this->assertAuthenticatedAs($productionStaff);
    }

    public function test_impersonated_user_cannot_start_a_nested_impersonation(): void
    {
        $administrator = $this->userWithRole('administrator');
        $firstTarget = $this->userWithRole('production-staff');
        $secondTarget = $this->userWithRole('inventory-staff');

        $this->actingAs($administrator)
            ->post(route('impersonation.store'), ['user_id' => $firstTarget->id])
            ->assertRedirect(route('dashboard'));

        $this->post(route('impersonation.store'), ['user_id' => $secondTarget->id])
            ->assertForbidden();

        $this->assertAuthenticatedAs($firstTarget);
        $this->assertSame($administrator->id, session('impersonator_id'));
    }

    public function test_administrator_cannot_impersonate_an_inactive_user(): void
    {
        $administrator = $this->userWithRole('administrator');
        $suspendedUser = $this->userWithRole('production-staff', ['status' => 'suspended']);

        $this->actingAs($administrator)
            ->from('/dashboard')
            ->post(route('impersonation.store'), ['user_id' => $suspendedUser->id])
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors('user_id');

        $this->assertAuthenticatedAs($administrator);
    }

    public function test_user_cannot_return_to_an_administrator_without_an_impersonation_session(): void
    {
        $productionStaff = $this->userWithRole('production-staff');

        $this->actingAs($productionStaff)
            ->post(route('impersonation.destroy'))
            ->assertForbidden();

        $this->assertAuthenticatedAs($productionStaff);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(string $roleSlug, array $attributes = []): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            ...$attributes,
            'role_id' => $role->id,
        ]);
    }
}
