<?php

namespace Tests\Feature;

use App\Livewire\UserActionsModal;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class StaffUserActionsTest extends TestCase
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

    private function actAsViewOnly(): User
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $usersRoles = DB::table('modules')->where('slug', 'users-roles')->value('id');
        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', $usersRoles)
            ->update(['access_level' => 'view']);

        $viewer = User::factory()->create(['role_id' => $productionManager->id]);
        $this->actingAs($viewer);

        return $viewer;
    }

    public function test_change_role_works_for_full_and_writes_audit(): void
    {
        $admin = auth()->user();
        $target = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'production-staff')->value('id'),
        ]);
        $managerId = Role::query()->where('slug', 'production-manager')->value('id');

        Livewire::test(UserActionsModal::class)
            ->call('openRoleModal', $target->id)
            ->set('roleId', $managerId)
            ->call('saveRole')
            ->assertHasNoErrors()
            ->assertDispatched('user-changed');

        $this->assertSame($managerId, $target->fresh()->role_id);
        $this->assertDatabaseHas('audit_log', [
            'user_id' => $admin->id,
            'target_user_id' => $target->id,
            'action' => 'user.role_changed',
            'table_name' => 'users',
        ]);

        $entry = DB::table('audit_log')->where('action', 'user.role_changed')->firstOrFail();

        $this->assertSame(['role' => 'production-staff'], json_decode($entry->old_values, true));
        $this->assertSame(['role' => 'production-manager'], json_decode($entry->new_values, true));
    }

    public function test_suspend_deletes_sessions_and_writes_audit(): void
    {
        $admin = auth()->user();
        $target = User::factory()->create(['status' => 'active']);
        DB::table('sessions')->insert([
            ['id' => 'staff-session', 'user_id' => $target->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => now()->timestamp],
        ]);

        Livewire::test(UserActionsModal::class)
            ->call('openSuspendModal', $target->id)
            ->call('confirmSuspend')
            ->assertHasNoErrors();

        $this->assertSame('suspended', $target->fresh()->status);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseHas('audit_log', [
            'user_id' => $admin->id,
            'target_user_id' => $target->id,
            'action' => 'user.suspended',
            'table_name' => 'users',
        ]);

        $entry = DB::table('audit_log')->where('action', 'user.suspended')->firstOrFail();

        $this->assertSame(['status' => 'active'], json_decode($entry->old_values, true));
        $this->assertSame(['status' => 'suspended'], json_decode($entry->new_values, true));
    }

    public function test_reactivate_restores_active_status_and_writes_audit(): void
    {
        $target = User::factory()->create(['status' => 'suspended']);

        Livewire::test(UserActionsModal::class)
            ->call('openSuspendModal', $target->id)
            ->call('confirmSuspend')
            ->assertHasNoErrors();

        $this->assertSame('active', $target->fresh()->status);
        $this->assertDatabaseHas('audit_log', [
            'target_user_id' => $target->id,
            'action' => 'user.reactivated',
            'table_name' => 'users',
        ]);
    }

    public function test_reset_password_sets_flag_shows_temp_once_and_writes_audit(): void
    {
        $admin = auth()->user();
        $target = User::factory()->create(['status' => 'active', 'must_change_password' => false]);

        $component = Livewire::test(UserActionsModal::class)
            ->call('openResetModal', $target->id)
            ->call('generateResetPassword')
            ->assertHasNoErrors()
            ->assertDispatched('user-changed');

        $temporaryPassword = $component->get('temporaryPassword');

        $component
            ->assertSee($temporaryPassword)
            ->assertSee('shown again');

        $fresh = $target->fresh();

        $this->assertTrue($fresh->must_change_password);
        $this->assertTrue(Hash::check($temporaryPassword, $fresh->password_hash));
        $this->assertNotSame($temporaryPassword, $fresh->password_hash);

        $entry = DB::table('audit_log')->where('action', 'user.password_reset')->firstOrFail();

        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame($target->id, $entry->target_user_id);
        $this->assertStringNotContainsString($temporaryPassword, json_encode($entry));
    }

    public function test_all_actions_are_forbidden_for_view_users(): void
    {
        $this->actAsViewOnly();
        $target = User::factory()->create(['status' => 'active']);

        $this->get('/staff')->assertOk()->assertDontSee('Actions for');

        Livewire::test(UserActionsModal::class)
            ->call('openRoleModal', $target->id)
            ->assertStatus(403);

        Livewire::test(UserActionsModal::class)
            ->call('openSuspendModal', $target->id)
            ->assertStatus(403);

        Livewire::test(UserActionsModal::class)
            ->call('openResetModal', $target->id)
            ->assertStatus(403);

        Livewire::test(UserActionsModal::class)
            ->set('targetUserId', $target->id)
            ->set('roleId', $target->role_id)
            ->call('saveRole')
            ->assertStatus(403);

        Livewire::test(UserActionsModal::class)
            ->set('targetUserId', $target->id)
            ->call('confirmSuspend')
            ->assertStatus(403);

        Livewire::test(UserActionsModal::class)
            ->set('targetUserId', $target->id)
            ->call('generateResetPassword')
            ->assertStatus(403);

        $this->assertSame('active', $target->fresh()->status);
        $this->assertDatabaseMissing('audit_log', ['target_user_id' => $target->id]);
    }

    public function test_last_active_administrator_is_protected(): void
    {
        $admin = auth()->user();
        $staffRoleId = Role::query()->where('slug', 'production-staff')->value('id');

        Livewire::test(UserActionsModal::class)
            ->call('openRoleModal', $admin->id)
            ->set('roleId', $staffRoleId)
            ->call('saveRole')
            ->assertHasErrors(['roleId']);

        Livewire::test(UserActionsModal::class)
            ->call('openSuspendModal', $admin->id)
            ->call('confirmSuspend')
            ->assertHasErrors(['suspend']);

        $fresh = $admin->fresh();

        $this->assertSame('active', $fresh->status);
        $this->assertSame(Role::query()->where('slug', 'administrator')->value('id'), $fresh->role_id);
    }

    public function test_admin_cannot_suspend_themselves(): void
    {
        $admin = auth()->user();
        User::factory()->create([
            'role_id' => Role::query()->where('slug', 'administrator')->value('id'),
            'status' => 'active',
        ]);

        Livewire::test(UserActionsModal::class)
            ->call('openSuspendModal', $admin->id)
            ->call('confirmSuspend')
            ->assertHasErrors(['suspend']);

        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_role_change_takes_effect_on_next_request(): void
    {
        $target = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'inventory-staff')->value('id'),
        ]);

        $admin = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'administrator'))->firstOrFail();

        $this->actingAs($target);
        $this->get('/production/schedule')->assertForbidden();

        $this->actingAs($admin);

        Livewire::test(UserActionsModal::class)
            ->call('openRoleModal', $target->id)
            ->set('roleId', Role::query()->where('slug', 'production-manager')->value('id'))
            ->call('saveRole')
            ->assertHasNoErrors();

        $this->actingAs($target->fresh());
        $this->get('/production/schedule')->assertOk();
    }
}
