<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
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

    public function test_sidebar_shows_view_and_full_modules_and_hides_none_modules(): void
    {
        $productionManager = $this->userWithRole('production-manager');

        $this->actingAs($productionManager)->get('/dashboard')
            ->assertSee('Dashboard')
            ->assertSee('Schedule')
            ->assertSee('Production Runs')
            ->assertSee('Stock Overview')
            ->assertSee('Orders')
            ->assertSee('Forecasting')
            ->assertDontSee('Users & Roles')
            ->assertDontSee('Audit Logs')
            ->assertDontSee('Reports')
            ->assertDontSee('Settings');
    }

    public function test_sidebar_uses_impersonated_users_permissions(): void
    {
        $administrator = $this->userWithRole('administrator');
        $productionStaff = $this->userWithRole('production-staff', ['name' => 'Production Tester']);

        $this->actingAs($administrator)
            ->post(route('impersonation.store'), ['user_id' => $productionStaff->id])
            ->assertRedirect(route('dashboard'));

        $this->get('/dashboard')
            ->assertSee('Dashboard')
            ->assertSee('Schedule')
            ->assertSee('Production Runs')
            ->assertDontSee('Stock Overview')
            ->assertDontSee('Users & Roles')
            ->assertDontSee('Audit Logs');
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
