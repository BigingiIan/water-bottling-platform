<?php

namespace Tests\Feature;

use App\Livewire\InviteUserModal;
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

class InviteUserTest extends TestCase
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

    private function invite(string $email = 'newhire@example.test', ?int $roleId = null): string
    {
        $roleId ??= Role::query()->where('slug', 'production-staff')->value('id');

        $component = Livewire::test(InviteUserModal::class)
            ->call('openModal')
            ->set('name', 'New Hire')
            ->set('email', $email)
            ->set('roleId', $roleId)
            ->call('saveInvite')
            ->assertHasNoErrors()
            ->assertDispatched('user-created');

        return $component->get('temporaryPassword');
    }

    public function test_creation_produces_pending_user_with_chosen_role_and_audit_entry(): void
    {
        $customRole = Role::create(['name' => 'Quality Auditor', 'slug' => 'quality-auditor']);
        $admin = auth()->user();

        Livewire::test(InviteUserModal::class)
            ->call('openModal')
            ->assertSee('Quality Auditor');

        $temporaryPassword = $this->invite('newhire@example.test', $customRole->id);

        $user = User::query()->where('email', 'newhire@example.test')->firstOrFail();

        $this->assertSame('pending', $user->status);
        $this->assertSame($customRole->id, $user->role_id);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($temporaryPassword, $user->password_hash));

        $this->assertDatabaseHas('audit_log', [
            'user_id' => $admin->id,
            'action' => 'user.created',
            'table_name' => 'users',
        ]);
    }

    public function test_temporary_password_is_shown_once_with_warning_then_cleared(): void
    {
        $component = Livewire::test(InviteUserModal::class)
            ->call('openModal')
            ->set('name', 'New Hire')
            ->set('email', 'newhire@example.test')
            ->set('roleId', Role::query()->where('slug', 'production-staff')->value('id'))
            ->call('saveInvite');

        $temporaryPassword = $component->get('temporaryPassword');

        $component
            ->assertSee($temporaryPassword)
            ->assertSee('shown again')
            ->call('closeModal')
            ->call('openModal')
            ->assertSet('temporaryPassword', null)
            ->assertDontSee($temporaryPassword);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        Livewire::test(InviteUserModal::class)
            ->call('openModal')
            ->set('name', 'New Hire')
            ->set('email', 'taken@example.test')
            ->set('roleId', Role::query()->where('slug', 'production-staff')->value('id'))
            ->call('saveInvite')
            ->assertHasErrors(['email']);

        $this->assertSame(0, User::query()->where('name', 'New Hire')->count());
    }

    public function test_view_user_gets_403_and_no_invite_button(): void
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $usersRoles = DB::table('modules')->where('slug', 'users-roles')->value('id');
        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', $usersRoles)
            ->update(['access_level' => 'view']);

        $this->actingAs(User::factory()->create(['role_id' => $productionManager->id]));

        $this->get('/staff')->assertOk()->assertDontSee('Invite User');

        Livewire::test(InviteUserModal::class)
            ->call('openModal')
            ->assertStatus(403);

        Livewire::test(InviteUserModal::class)
            ->set('name', 'New Hire')
            ->set('email', 'newhire@example.test')
            ->set('roleId', $productionManager->id)
            ->call('saveInvite')
            ->assertStatus(403);

        $this->assertSame(0, User::query()->where('email', 'newhire@example.test')->count());
    }

    public function test_admin_sees_invite_button_on_staff_page(): void
    {
        $this->get('/staff')->assertOk()->assertSee('Invite User');
    }

    public function test_forced_password_change_flow(): void
    {
        $temporaryPassword = $this->invite('newhire@example.test');

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'newhire@example.test',
            'password' => $temporaryPassword,
        ])->assertRedirect(route('password.set'));

        $this->get('/dashboard')->assertRedirect(route('password.set'));

        $this->get(route('password.set'))->assertOk()->assertSee('Set your password');

        $this->post(route('password.set.update'), [
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ])->assertSessionHasErrors('password');

        $this->post(route('password.set.update'), [
            'password' => $temporaryPassword,
            'password_confirmation' => $temporaryPassword,
        ])->assertSessionHasErrors('password');

        $this->post(route('password.set.update'), [
            'password' => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'newhire@example.test')->firstOrFail();

        $this->assertSame('active', $user->status);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('brand-new-password-1', $user->password_hash));

        $this->get('/dashboard')->assertOk();
    }

    public function test_suspended_user_login_is_rejected_with_generic_message(): void
    {
        User::factory()->create([
            'email' => 'suspended@example.test',
            'password_hash' => Hash::make('secret-password'),
            'status' => 'suspended',
        ]);

        $this->post('/logout');

        $this->from('/login')->post('/login', [
            'email' => 'suspended@example.test',
            'password' => 'secret-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('email', __('auth.failed'));

        $this->assertGuest();
    }

    public function test_suspended_user_sessions_are_invalidated(): void
    {
        $user = User::factory()->create([
            'password_hash' => Hash::make('secret-password'),
            'status' => 'suspended',
        ]);

        DB::table('sessions')->insert([
            ['id' => 'session-one', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => now()->timestamp],
            ['id' => 'session-two', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => now()->timestamp],
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_plaintext_temporary_password_is_not_stored_anywhere(): void
    {
        $temporaryPassword = $this->invite('newhire@example.test');

        $user = User::query()->where('email', 'newhire@example.test')->firstOrFail();

        $this->assertNotSame($temporaryPassword, $user->password_hash);

        $stored = (array) DB::table('users')->where('id', $user->id)->first();

        foreach ($stored as $value) {
            $this->assertNotSame($temporaryPassword, (string) $value);
        }

        $auditRows = DB::table('audit_log')->get();

        $this->assertNotEmpty($auditRows);

        foreach ($auditRows as $row) {
            $this->assertStringNotContainsString($temporaryPassword, json_encode($row));
        }
    }
}
