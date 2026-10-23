<?php

namespace Tests\Feature;

use App\Livewire\RolePermissionMatrix;
use App\Livewire\StaffDirectory;
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

        $this->assertSame(45, DB::table('role_module_permissions')->count());
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

        Livewire::test(RolePermissionMatrix::class)
            ->assertDontSee('+ New Role')
            ->assertDontSee('Rename')
            ->assertDontSee('Delete');

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

    public function test_new_role_creates_a_none_permission_for_every_module_and_appears_in_the_matrix(): void
    {
        Livewire::test(RolePermissionMatrix::class)
            ->call('openCreateRoleModal')
            ->set('name', 'Quality Lead')
            ->set('description', 'Quality assurance access')
            ->call('saveRole')
            ->assertHasNoErrors()
            ->assertSee('Quality Lead');

        $role = Role::query()->where('slug', 'quality-lead')->firstOrFail();

        $this->assertSame('Quality assurance access', $role->description);
        $this->assertSame(
            Module::query()->count(),
            DB::table('role_module_permissions')->where('role_id', $role->id)->count(),
        );
        $this->assertSame(
            array_fill(0, Module::query()->count(), 'none'),
            DB::table('role_module_permissions')
                ->where('role_id', $role->id)
                ->orderBy('module_id')
                ->pluck('access_level')
                ->all(),
        );
    }

    public function test_duplicate_role_name_is_rejected_without_creating_a_role(): void
    {
        $roleCount = Role::query()->count();

        Livewire::test(RolePermissionMatrix::class)
            ->call('openCreateRoleModal')
            ->set('name', 'Administrator')
            ->call('saveRole')
            ->assertHasErrors(['name' => 'unique'])
            ->assertSee('The name has already been taken.');

        $this->assertSame($roleCount, Role::query()->count());
    }

    public function test_role_slugs_are_unique_and_renaming_updates_the_slug(): void
    {
        Livewire::test(RolePermissionMatrix::class)
            ->call('openCreateRoleModal')
            ->set('name', 'Quality Lead')
            ->call('saveRole')
            ->assertHasNoErrors();

        $firstRole = Role::query()->where('slug', 'quality-lead')->firstOrFail();

        Livewire::test(RolePermissionMatrix::class)
            ->call('openCreateRoleModal')
            ->set('name', 'Quality-Lead')
            ->call('saveRole')
            ->assertHasNoErrors();

        $secondRole = Role::query()->where('slug', 'quality-lead-2')->firstOrFail();

        Livewire::test(RolePermissionMatrix::class)
            ->call('openEditRoleModal', $secondRole->id)
            ->set('name', 'Quality Supervisor')
            ->set('description', 'Supervises quality')
            ->call('saveRole')
            ->assertHasNoErrors();

        $this->assertSame('quality-supervisor', $secondRole->fresh()->slug);
        $this->assertSame('Supervises quality', $secondRole->fresh()->description);
        $this->assertSame('quality-lead', $firstRole->fresh()->slug);
    }

    public function test_view_access_cannot_open_role_management_actions(): void
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $usersAndRoles = Module::query()->where('slug', 'users-roles')->firstOrFail();
        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', $usersAndRoles->id)
            ->update(['access_level' => 'view']);

        $this->actingAs(User::factory()->create(['role_id' => $productionManager->id]));

        Livewire::test(RolePermissionMatrix::class)
            ->call('openCreateRoleModal')
            ->assertStatus(403);

        $this->assertDatabaseCount('roles', 5);
    }

    public function test_custom_role_cannot_be_deleted_while_users_are_assigned(): void
    {
        $role = Role::query()->create([
            'name' => 'Quality Lead',
            'slug' => 'quality-lead',
        ]);
        $users = User::factory()->count(6)->sequence(
            ['name' => 'Alex Avery'],
            ['name' => 'Blair Brooks'],
            ['name' => 'Casey Carter'],
            ['name' => 'Drew Dawson'],
            ['name' => 'Elliot Evans'],
            ['name' => 'Frank Flores'],
        )->create(['role_id' => $role->id]);

        Livewire::test(RolePermissionMatrix::class)
            ->call('openDeleteRoleModal', $role->id)
            ->assertSee('6 users still have the role \'Quality Lead\'. Reassign them before deleting it.', false)
            ->assertSee('Alex Avery')
            ->assertSee('Elliot Evans')
            ->assertDontSee('Frank Flores')
            ->assertSee('and 1 more')
            ->assertSee(route('staff.directory', ['role' => $role->id]))
            ->assertSee('View users')
            ->assertSee('Close')
            ->assertDontSee('Delete role')
            ->call('deleteRole')
            ->assertSee('6 users still have the role \'Quality Lead\'. Reassign them before deleting it.');

        $this->assertCount(6, $users);
        $this->assertModelExists($role);
    }

    public function test_custom_role_without_users_can_be_deleted_and_audited(): void
    {
        $role = Role::query()->create([
            'name' => 'Quality Lead',
            'slug' => 'quality-lead',
        ]);
        $actor = auth()->user();

        Livewire::test(RolePermissionMatrix::class)
            ->call('openDeleteRoleModal', $role->id)
            ->assertSee('Delete role \'Quality Lead\'?', false)
            ->assertSee('Users with this role: 0')
            ->assertSee('Modules with access above \'none\': 0', false)
            ->assertSee('This cannot be undone.')
            ->assertSee('Cancel')
            ->assertSee('Delete role')
            ->call('deleteRole')
            ->assertSet('showDeleteRoleModal', false)
            ->assertDispatched('role-deleted', message: 'Role \'Quality Lead\' deleted.')
            ->assertDontSee('Quality Lead');

        $this->assertModelMissing($role);
        $audit = DB::table('audit_log')->where('action', 'roles.deleted')->first();
        $this->assertNotNull($audit);
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame('roles', $audit->table_name);
        $this->assertSame(
            ['name' => 'Quality Lead', 'user_count' => 0],
            json_decode($audit->old_values, true),
        );
    }

    public function test_administrator_cannot_have_its_permissions_reduced(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $module = Module::query()->where('slug', 'dashboard')->firstOrFail();

        Livewire::test(RolePermissionMatrix::class)
            ->call('cyclePermission', $administrator->id, $module->id)
            ->assertStatus(403);

        $this->assertSame(
            'full',
            DB::table('role_module_permissions')
                ->where('role_id', $administrator->id)
                ->where('module_id', $module->id)
                ->value('access_level'),
        );
    }

    public function test_administrator_cannot_be_renamed_or_deleted(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();

        Livewire::test(RolePermissionMatrix::class)
            ->call('openEditRoleModal', $administrator->id)
            ->assertStatus(403);

        Livewire::test(RolePermissionMatrix::class)
            ->call('openDeleteRoleModal', $administrator->id)
            ->assertSee('\'Administrator\' is a system role and cannot be deleted.', false)
            ->assertSee('Close')
            ->assertDontSee('Delete role')
            ->call('deleteRole')
            ->assertSee('\'Administrator\' is a system role and cannot be deleted.', false);

        $this->assertSame('Administrator', $administrator->fresh()->name);
        $this->assertModelExists($administrator);
    }

    public function test_view_only_user_cannot_call_delete_role_method(): void
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $usersAndRoles = Module::query()->where('slug', 'users-roles')->firstOrFail();
        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', $usersAndRoles->id)
            ->update(['access_level' => 'view']);
        $this->actingAs(User::factory()->create(['role_id' => $productionManager->id]));

        Livewire::test(RolePermissionMatrix::class)
            ->call('deleteRole')
            ->assertStatus(403);
    }

    public function test_role_assigned_after_confirmation_shows_inline_error_and_is_not_audited(): void
    {
        $role = Role::query()->create([
            'name' => 'Quality Lead',
            'slug' => 'quality-lead',
        ]);
        $auditCount = DB::table('audit_log')->count();
        $component = Livewire::test(RolePermissionMatrix::class)
            ->call('openDeleteRoleModal', $role->id);

        User::factory()->create(['role_id' => $role->id]);

        $component->call('deleteRole')
            ->assertSet('showDeleteRoleModal', true)
            ->assertSee('1 user still has the role \'Quality Lead\'. Reassign them before deleting it.', false)
            ->assertSee('role="alert"', false);

        $this->assertModelExists($role);
        $this->assertSame($auditCount, DB::table('audit_log')->count());
    }

    public function test_new_role_is_available_in_the_staff_role_dropdown(): void
    {
        Livewire::test(RolePermissionMatrix::class)
            ->call('openCreateRoleModal')
            ->set('name', 'Quality Lead')
            ->call('saveRole')
            ->assertHasNoErrors();

        Livewire::test(StaffDirectory::class)
            ->assertSee('Quality Lead');
    }
}
