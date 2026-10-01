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

class RolePermissionMatrixTest extends TestCase
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

        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $this->actingAs(User::factory()->create(['role_id' => $administrator->id]));
    }

    public function test_matrix_displays_every_role_and_module_with_sticky_module_column(): void
    {
        $response = $this->get('/staff/permissions');

        $response->assertOk()
            ->assertSee('Production Manager')
            ->assertSee('Dashboard')
            ->assertSee('wire:click="cyclePermission(', false)
            ->assertSee('sticky left-0', false);
    }

    public function test_toggle_cycles_and_persists_all_three_access_levels(): void
    {
        $role = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $module = Module::query()->where('slug', 'equipment')->firstOrFail();
        $permission = DB::table('role_module_permissions')
            ->where('role_id', $role->id)
            ->where('module_id', $module->id);

        $this->assertSame('none', $permission->value('access_level'));
        $initialCount = DB::table('role_module_permissions')->count();

        Livewire::test(RolePermissionMatrix::class)
            ->call('cyclePermission', $role->id, $module->id)
            ->assertHasNoErrors();
        $this->assertSame('view', $permission->value('access_level'));

        Livewire::test(RolePermissionMatrix::class)
            ->call('cyclePermission', $role->id, $module->id)
            ->assertHasNoErrors();
        $this->assertSame('full', $permission->value('access_level'));

        Livewire::test(RolePermissionMatrix::class)
            ->call('cyclePermission', $role->id, $module->id)
            ->assertHasNoErrors();
        $this->assertSame('none', $permission->value('access_level'));
        $this->assertSame($initialCount, DB::table('role_module_permissions')->count());
    }

    public function test_toggle_rejects_nonexistent_role_or_module_ids(): void
    {
        Livewire::test(RolePermissionMatrix::class)
            ->call('cyclePermission', 999, 999)
            ->assertHasErrors(['role_id', 'module_id']);

        $this->assertSame(40, DB::table('role_module_permissions')->count());
    }

    public function test_role_with_no_users_and_roles_access_cannot_open_matrix(): void
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $this->actingAs(User::factory()->create(['role_id' => $productionManager->id]));

        $this->get('/staff/permissions')->assertForbidden();
    }

    public function test_view_access_allows_reading_matrix_but_not_changing_permissions(): void
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $usersAndRoles = Module::query()->where('slug', 'users-roles')->firstOrFail();
        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', $usersAndRoles->id)
            ->update(['access_level' => 'view']);

        $user = User::factory()->create(['role_id' => $productionManager->id]);
        $this->actingAs($user);

        $this->get('/staff/permissions')->assertOk();

        $role = Role::query()->where('slug', 'production-staff')->firstOrFail();
        $module = Module::query()->where('slug', 'production')->firstOrFail();

        Livewire::test(RolePermissionMatrix::class)
            ->call('cyclePermission', $role->id, $module->id)
            ->assertStatus(403);

        $this->assertSame(
            'full',
            DB::table('role_module_permissions')
                ->where('role_id', $role->id)
                ->where('module_id', $module->id)
                ->value('access_level'),
        );
    }
}
