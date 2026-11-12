<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Livewire\InviteUserModal;
use App\Livewire\UserActionsModal;
use App\Mail\UserInvitation;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TestUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class AccountHardeningTest extends TestCase
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

    public function test_test_user_seeder_refuses_to_run_outside_local_and_testing(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        try {
            app(TestUserSeeder::class)->run();
            $this->fail('TestUserSeeder ran in production.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('restricted to local and testing', $exception->getMessage());
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_set_is_refused_for_a_user_without_the_forced_change_flag(): void
    {
        $user = $this->userWithRole('production-staff', ['password_hash' => Hash::make('current-password-1')]);

        $this->actingAs($user)
            ->post(route('password.set.update'), [
                'password' => 'hijacked-password-1',
                'password_confirmation' => 'hijacked-password-1',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(Hash::check('current-password-1', $user->fresh()->password_hash));
        $this->assertDatabaseMissing('audit_log', ['action' => 'user.password_set']);

        $this->get(route('password.set'))->assertRedirect(route('dashboard'));
    }

    public function test_password_set_is_refused_while_viewing_as_another_user(): void
    {
        $administrator = $this->userWithRole('administrator');
        $target = $this->userWithRole('production-staff', [
            'password_hash' => Hash::make('temporary-password-1'),
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->addDay(),
        ]);

        $this->actingAs($administrator)
            ->post(route('impersonation.store'), ['user_id' => $target->id]);
        $this->assertAuthenticatedAs($target);

        $this->get(route('password.set'))
            ->assertOk()
            ->assertSee('Passwords cannot be set while using View As.')
            ->assertSee('Return to Admin')
            ->assertDontSee('name="password"', false);

        $this->post(route('password.set.update'), [
            'password' => 'hijacked-password-1',
            'password_confirmation' => 'hijacked-password-1',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('temporary-password-1', $target->fresh()->password_hash));
        $this->assertTrue($target->fresh()->must_change_password);
    }

    public function test_password_set_audits_clears_the_expiry_and_signs_out_other_sessions(): void
    {
        $user = $this->userWithRole('production-staff', [
            'status' => 'pending',
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->addDay(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'another-device',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)
            ->post(route('password.set.update'), [
                'password' => 'brand-new-password-1',
                'password_confirmation' => 'brand-new-password-1',
            ])
            ->assertRedirect(route('dashboard'));

        $user->refresh();

        $this->assertSame('active', $user->status);
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->temporary_password_expires_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'another-device']);

        $audit = AuditLog::query()->where('action', 'user.password_set')->sole();

        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame($user->id, $audit->target_user_id);
        $this->assertSame(1, $audit->new_values['other_sessions_logged_out']);
        $this->assertStringNotContainsString('brand-new-password-1', (string) json_encode($audit->getAttributes()));
    }

    public function test_impersonation_start_and_end_are_audited_with_both_parties(): void
    {
        $administrator = $this->userWithRole('administrator');
        $target = $this->userWithRole('production-staff');

        $this->actingAs($administrator)
            ->post(route('impersonation.store'), ['user_id' => $target->id]);
        $this->post(route('impersonation.destroy'));

        foreach (['impersonation.started', 'impersonation.ended'] as $action) {
            $audit = AuditLog::query()->where('action', $action)->sole();

            $this->assertSame($administrator->id, $audit->user_id);
            $this->assertSame($target->id, $audit->target_user_id);
            $this->assertSame($administrator->email, $audit->new_values['administrator_email']);
            $this->assertSame($target->email, $audit->new_values['target_email']);
        }
    }

    public function test_non_administrator_with_full_access_cannot_grant_or_remove_the_administrator_role(): void
    {
        $this->actAsUserManager();
        $administrator = $this->userWithRole('administrator');
        $this->userWithRole('administrator');
        $staff = $this->userWithRole('production-staff');
        $administratorRoleId = Role::query()->where('slug', 'administrator')->value('id');

        Livewire::test(UserActionsModal::class)
            ->call('openRoleModal', $staff->id)
            ->set('roleId', $administratorRoleId)
            ->call('saveRole')
            ->assertHasErrors('roleId')
            ->assertSee('Only an Administrator can grant or remove the Administrator role.');

        Livewire::test(UserActionsModal::class)
            ->call('openRoleModal', $administrator->id)
            ->set('roleId', $staff->role_id)
            ->call('saveRole')
            ->assertHasErrors('roleId');

        $this->assertSame('production-staff', $staff->fresh()->role->slug);
        $this->assertSame('administrator', $administrator->fresh()->role->slug);
        $this->assertDatabaseMissing('audit_log', ['action' => 'user.role_changed']);
    }

    public function test_non_administrator_with_full_access_cannot_reset_suspend_or_reactivate_an_administrator(): void
    {
        $this->actAsUserManager();
        $administrator = $this->userWithRole('administrator');
        $suspendedAdministrator = $this->userWithRole('administrator', ['status' => 'suspended']);
        $this->userWithRole('administrator');
        $originalHash = $administrator->password_hash;

        Livewire::test(UserActionsModal::class)
            ->call('openResetModal', $administrator->id)
            ->call('generateResetPassword')
            ->assertHasErrors('reset')
            ->assertSet('temporaryPassword', null);

        Livewire::test(UserActionsModal::class)
            ->call('openSuspendModal', $administrator->id)
            ->call('confirmSuspend')
            ->assertHasErrors('suspend')
            ->assertSee('Only an Administrator can suspend or reactivate an Administrator.');

        Livewire::test(UserActionsModal::class)
            ->call('openSuspendModal', $suspendedAdministrator->id)
            ->call('confirmSuspend')
            ->assertHasErrors('suspend');

        $administrator->refresh();

        $this->assertSame($originalHash, $administrator->password_hash);
        $this->assertFalse($administrator->must_change_password);
        $this->assertSame('active', $administrator->status);
        $this->assertSame('suspended', $suspendedAdministrator->fresh()->status);
    }

    public function test_non_administrator_with_full_access_can_still_manage_ordinary_users(): void
    {
        $this->actAsUserManager();
        $staff = $this->userWithRole('production-staff');
        $inventoryRoleId = Role::query()->where('slug', 'inventory-staff')->value('id');

        Livewire::test(UserActionsModal::class)
            ->call('openRoleModal', $staff->id)
            ->set('roleId', $inventoryRoleId)
            ->call('saveRole')
            ->assertHasNoErrors();

        Livewire::test(UserActionsModal::class)
            ->call('openResetModal', $staff->id)
            ->call('generateResetPassword')
            ->assertHasNoErrors();

        $this->assertSame('inventory-staff', $staff->fresh()->role->slug);
        $this->assertTrue($staff->fresh()->must_change_password);
    }

    public function test_only_an_administrator_can_invite_an_administrator(): void
    {
        Mail::fake();
        $this->actAsUserManager();
        $administratorRoleId = Role::query()->where('slug', 'administrator')->value('id');

        Livewire::test(InviteUserModal::class)
            ->call('openModal')
            ->set('name', 'New Admin')
            ->set('email', 'newadmin@example.test')
            ->set('roleId', $administratorRoleId)
            ->call('saveInvite')
            ->assertHasErrors('roleId')
            ->assertSee('Only an Administrator can invite another Administrator.');

        $this->assertDatabaseMissing('users', ['email' => 'newadmin@example.test']);
        Mail::assertNothingSent();

        $this->actingAs($this->userWithRole('administrator'));

        Livewire::test(InviteUserModal::class)
            ->call('openModal')
            ->set('name', 'New Admin')
            ->set('email', 'newadmin@example.test')
            ->set('roleId', $administratorRoleId)
            ->call('saveInvite')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'newadmin@example.test', 'role_id' => $administratorRoleId]);
    }

    public function test_invitation_sets_a_72_hour_expiry_and_the_email_states_it(): void
    {
        Mail::fake();
        $this->freezeTime();
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::test(InviteUserModal::class)
            ->call('openModal')
            ->set('name', 'New Hire')
            ->set('email', 'newhire@example.test')
            ->set('roleId', Role::query()->where('slug', 'production-staff')->value('id'))
            ->call('saveInvite')
            ->assertHasNoErrors();

        $invited = User::query()->where('email', 'newhire@example.test')->firstOrFail();

        $this->assertSame(now()->addHours(72)->timestamp, $invited->temporary_password_expires_at->timestamp);

        (new UserInvitation('New Hire', 'newhire@example.test', 'temporary-password'))
            ->assertSeeInHtml('expires 72 hours after this email was sent');
    }

    public function test_expired_temporary_password_cannot_sign_in_and_a_reset_issues_a_fresh_window(): void
    {
        $administrator = $this->userWithRole('administrator');
        $pending = $this->userWithRole('production-staff', [
            'email' => 'pending@example.test',
            'password_hash' => Hash::make('temporary-password-1'),
            'status' => 'pending',
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->subMinute(),
        ]);

        $this->post('/login', ['email' => 'pending@example.test', 'password' => 'temporary-password-1'])
            ->assertSessionHasErrors(['email' => AuthenticatedSessionController::EXPIRED_TEMPORARY_PASSWORD_MESSAGE]);
        $this->assertGuest();

        $this->post('/login', ['email' => 'pending@example.test', 'password' => 'not-the-password'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();

        $this->actingAs($administrator);
        $temporaryPassword = Livewire::test(UserActionsModal::class)
            ->call('openResetModal', $pending->id)
            ->call('generateResetPassword')
            ->assertHasNoErrors()
            ->get('temporaryPassword');
        $this->post('/logout');

        $this->assertTrue($pending->fresh()->temporary_password_expires_at->isFuture());

        $this->post('/login', ['email' => 'pending@example.test', 'password' => $temporaryPassword])
            ->assertRedirect(route('password.set'));
        $this->assertAuthenticatedAs($pending);
    }

    public function test_expired_temporary_password_attempts_still_count_towards_the_login_throttle(): void
    {
        $this->userWithRole('production-staff', [
            'email' => 'pending@example.test',
            'password_hash' => Hash::make('temporary-password-1'),
            'status' => 'pending',
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->subMinute(),
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => 'pending@example.test', 'password' => 'temporary-password-1']);
        }

        $response = $this->post('/login', ['email' => 'pending@example.test', 'password' => 'temporary-password-1']);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
    }

    public function test_a_session_holding_an_expired_temporary_password_cannot_set_a_password(): void
    {
        $pending = $this->userWithRole('production-staff', [
            'password_hash' => Hash::make('temporary-password-1'),
            'status' => 'pending',
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($pending)
            ->post(route('password.set.update'), [
                'password' => 'brand-new-password-1',
                'password_confirmation' => 'brand-new-password-1',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => AuthenticatedSessionController::EXPIRED_TEMPORARY_PASSWORD_MESSAGE]);

        $this->assertGuest();
        $this->assertTrue(Hash::check('temporary-password-1', $pending->fresh()->password_hash));
    }

    /**
     * Sign in as a non-administrator whose role has full access to the users-roles module.
     */
    private function actAsUserManager(): User
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();

        DB::table('role_module_permissions')->updateOrInsert(
            ['role_id' => $productionManager->id, 'module_id' => DB::table('modules')->where('slug', 'users-roles')->value('id')],
            ['access_level' => 'full'],
        );

        $manager = User::factory()->create(['role_id' => $productionManager->id]);
        $this->actingAs($manager);

        return $manager;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(string $roleSlug, array $attributes = []): User
    {
        return User::factory()->create([
            ...$attributes,
            'role_id' => Role::query()->where('slug', $roleSlug)->firstOrFail()->id,
        ]);
    }
}
