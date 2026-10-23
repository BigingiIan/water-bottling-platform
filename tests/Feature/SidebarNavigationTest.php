<?php

namespace Tests\Feature;

use App\Livewire\RolePermissionMatrix;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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
            ->assertDontSee('Roles & Permissions')
            ->assertDontSee('Error Logs')
            ->assertDontSee('Audit Logs')
            ->assertDontSee('Reports')
            ->assertDontSee('Settings');
    }

    public function test_inventory_route_marks_stock_overview_as_the_active_sidebar_item(): void
    {
        $administrator = $this->userWithRole('administrator');

        $this->actingAs($administrator)
            ->get(route('inventory.overview'))
            ->assertOk()
            ->assertSeeInOrder([
                'href="'.route('inventory.overview').'"',
                'aria-current="page"',
                '>Stock Overview</span>',
            ], false);
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
            ->assertDontSee('Roles & Permissions')
            ->assertDontSee('Error Logs')
            ->assertDontSee('Audit Logs');
    }

    public function test_roles_and_permissions_sidebar_item_requires_full_users_and_roles_access(): void
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $usersAndRoles = Module::query()->where('slug', 'users-roles')->firstOrFail();
        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', $usersAndRoles->id)
            ->update(['access_level' => 'view']);

        $this->actingAs($this->userWithRole('production-manager'))
            ->get('/dashboard')
            ->assertSee('Users & Roles')
            ->assertDontSee('Roles & Permissions');

        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', $usersAndRoles->id)
            ->update(['access_level' => 'full']);

        $this->get('/dashboard')
            ->assertSee('Roles & Permissions')
            ->assertSee(route('staff.permissions'));
    }

    public function test_created_custom_role_uses_its_permission_rows_for_sidebar_access(): void
    {
        $administrator = $this->userWithRole('administrator');

        $this->actingAs($administrator);

        Livewire::test(RolePermissionMatrix::class)
            ->call('openCreateRoleModal')
            ->set('name', 'Quality Lead')
            ->call('saveRole')
            ->assertHasNoErrors();

        $qualityLead = Role::query()->where('slug', 'quality-lead')->firstOrFail();
        DB::table('role_module_permissions')
            ->where('role_id', $qualityLead->id)
            ->whereIn('module_id', Module::query()->whereIn('slug', ['dashboard', 'users-roles'])->pluck('id'))
            ->update(['access_level' => 'full']);

        $this->actingAs(User::factory()->create(['role_id' => $qualityLead->id]))
            ->get('/dashboard')
            ->assertSee('Roles & Permissions')
            ->assertSee(route('staff.permissions'));
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
