<?php

namespace Tests\Feature;

use App\Livewire\NotificationsPage;
use App\Livewire\ProfilePage;
use App\Models\AuditLog;
use App\Models\LeakageReport;
use App\Models\Role;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\UserPreferenceService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
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

    public function test_any_signed_in_user_can_open_their_profile_and_edit_their_own_name(): void
    {
        $user = $this->userWithRole('maintenance-staff', ['name' => 'Old Name']);
        $this->actingAs($user);

        $this->get(route('profile.show'))
            ->assertOk()
            ->assertSee('My Profile')
            ->assertSee($user->email)
            ->assertSee('Maintenance');

        Livewire::test(ProfilePage::class)
            ->set('name', 'New Name')
            ->call('saveDetails')
            ->assertHasNoErrors()
            ->assertDispatched('profile-saved');

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertDatabaseHas('audit_log', [
            'user_id' => $user->id,
            'target_user_id' => $user->id,
            'action' => 'profile.name_changed',
        ]);
    }

    public function test_profile_only_ever_edits_the_signed_in_user(): void
    {
        $user = $this->userWithRole('inventory-staff');
        $otherUser = $this->userWithRole('inventory-staff', ['name' => 'Untouched', 'email' => 'other@example.com']);
        $this->actingAs($user);

        $this->get(route('profile.show', ['user' => $otherUser->id, 'user_id' => $otherUser->id]))
            ->assertOk()
            ->assertSee($user->email)
            ->assertDontSee('other@example.com');

        Livewire::withQueryParams(['user_id' => $otherUser->id])
            ->test(ProfilePage::class)
            ->set('name', 'Hijacked')
            ->set('email', 'hijacked@example.com')
            ->call('saveDetails');

        $this->assertSame('Untouched', $otherUser->fresh()->name);
        $this->assertSame('other@example.com', $otherUser->fresh()->email);
        $this->assertSame('Hijacked', $user->fresh()->name);
        $this->assertNotSame('hijacked@example.com', $user->fresh()->email);
    }

    public function test_email_is_editable_only_with_full_users_module_access(): void
    {
        $administrator = $this->userWithRole('administrator');
        $this->actingAs($administrator);

        Livewire::test(ProfilePage::class)
            ->set('email', 'admin-new@example.com')
            ->call('saveDetails')
            ->assertHasNoErrors();

        $this->assertSame('admin-new@example.com', $administrator->fresh()->email);
        $this->assertDatabaseHas('audit_log', ['action' => 'profile.email_changed', 'user_id' => $administrator->id]);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = $this->userWithRole('inventory-staff');
        $this->actingAs($user);

        Livewire::test(ProfilePage::class)
            ->set('activeTab', 'password')
            ->set('currentPassword', 'not-my-password')
            ->set('newPassword', 'brand-new-password')
            ->set('newPasswordConfirmation', 'brand-new-password')
            ->call('changePassword')
            ->assertHasErrors('currentPassword')
            ->assertSet('currentPassword', '')
            ->assertSet('newPassword', '');

        $this->assertTrue(Hash::check('password', $user->fresh()->password_hash));
    }

    public function test_password_rules_come_from_settings(): void
    {
        $administrator = $this->userWithRole('administrator');
        app(SettingsService::class)->set('security.password_min_length', 12, $administrator);
        $user = $this->userWithRole('inventory-staff');
        $this->actingAs($user);

        Livewire::test(ProfilePage::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'elevenchars')
            ->set('newPasswordConfirmation', 'elevenchars')
            ->call('changePassword')
            ->assertHasErrors(['newPassword' => 'min']);

        Livewire::test(ProfilePage::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'twelve-chars')
            ->set('newPasswordConfirmation', 'twelve-chars')
            ->call('changePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('twelve-chars', $user->fresh()->password_hash));
    }

    public function test_password_change_clears_forced_change_logs_out_other_sessions_and_audits_without_the_password(): void
    {
        $user = $this->userWithRole('inventory-staff', ['must_change_password' => true]);
        $otherUser = $this->userWithRole('inventory-staff');
        $this->actingAs($user);
        $this->insertSession(session()->getId(), $user);
        $this->insertSession('other-device-session', $user);
        $this->insertSession('someone-elses-session', $otherUser);

        Livewire::test(ProfilePage::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'a-new-secret-value')
            ->set('newPasswordConfirmation', 'a-new-secret-value')
            ->call('changePassword')
            ->assertHasNoErrors()
            ->assertDispatched('profile-saved', message: 'Password changed. Your other sessions were signed out.');

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertDatabaseHas('sessions', ['id' => session()->getId()]);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'someone-elses-session']);

        $auditEntry = AuditLog::query()->where('action', 'profile.password_changed')->sole();
        $this->assertStringNotContainsString('a-new-secret-value', json_encode($auditEntry->getAttributes()));
        $this->assertStringNotContainsString('password_hash', json_encode($auditEntry->getAttributes()));
    }

    public function test_password_changes_are_rate_limited_to_five_per_ten_minutes(): void
    {
        $user = $this->userWithRole('inventory-staff');
        $this->actingAs($user);
        $component = Livewire::test(ProfilePage::class)->set('activeTab', 'password');

        foreach (range(1, 5) as $attempt) {
            $component->set('currentPassword', 'wrong-password')->call('changePassword')->assertHasErrors('currentPassword');
        }

        $component
            ->set('currentPassword', 'password')
            ->set('newPassword', 'brand-new-password')
            ->set('newPasswordConfirmation', 'brand-new-password')
            ->call('changePassword')
            ->assertSee('Too many attempts');

        $this->assertTrue(Hash::check('password', $user->fresh()->password_hash));

        $this->travel(11)->minutes();

        $component
            ->set('currentPassword', 'password')
            ->set('newPassword', 'brand-new-password')
            ->set('newPasswordConfirmation', 'brand-new-password')
            ->call('changePassword')
            ->assertHasNoErrors();
    }

    public function test_sessions_tab_lists_own_sessions_and_logs_out_others_after_password_confirmation(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->userWithRole('inventory-staff');
        $this->actingAs($user);
        $this->insertSession(session()->getId(), $user, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Edg/130.0');
        $this->insertSession('phone-session', $user, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', '10.0.0.9');

        $component = Livewire::test(ProfilePage::class)
            ->set('activeTab', 'sessions')
            ->assertSee('Edge on Windows')
            ->assertSee('Safari on iPhone')
            ->assertSee('10.0.0.9')
            ->assertSee('This device')
            ->assertDontSee('phone-session');

        $component
            ->call('openLogoutSessionsModal')
            ->set('sessionsPassword', 'wrong')
            ->call('logoutOtherSessions')
            ->assertHasErrors('sessionsPassword');
        $this->assertDatabaseHas('sessions', ['id' => 'phone-session']);

        $component
            ->set('sessionsPassword', 'password')
            ->call('logoutOtherSessions')
            ->assertHasNoErrors()
            ->assertSet('showLogoutSessionsModal', false);

        $this->assertDatabaseMissing('sessions', ['id' => 'phone-session']);
        $this->assertDatabaseHas('sessions', ['id' => session()->getId()]);
        $this->assertDatabaseHas('audit_log', ['action' => 'profile.sessions_logged_out', 'user_id' => $user->id]);
    }

    public function test_preferences_cannot_enable_an_alert_that_settings_disabled(): void
    {
        $administrator = $this->userWithRole('administrator');
        app(SettingsService::class)->set('notify.low_stock', false, $administrator);
        $user = $this->userWithRole('inventory-staff');
        $this->actingAs($user);

        Livewire::test(ProfilePage::class)
            ->set('activeTab', 'preferences')
            ->assertSet('notificationPreferences.notify_low_stock', false)
            ->assertSee('Turned off for everyone in Settings.')
            ->assertDontSee('System error alerts')
            ->set('notificationPreferences.notify_low_stock', true)
            ->call('savePreferences')
            ->assertHasErrors('notifications.notify_low_stock');

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id, 'key' => 'notify.low_stock']);
    }

    public function test_notification_service_skips_users_who_turned_an_alert_off(): void
    {
        $optedOut = $this->userWithRole('inventory-staff');
        $optedIn = $this->userWithRole('inventory-staff');
        $this->actingAs($optedOut);

        Livewire::test(ProfilePage::class)
            ->set('activeTab', 'preferences')
            ->set('notificationPreferences.notify_leakage_flag', false)
            ->call('savePreferences')
            ->assertHasNoErrors();

        $report = new LeakageReport(['batch_id' => 1, 'expected_volume' => 100, 'actual_volume' => 90, 'discrepancy' => -10]);
        $report->id = 99;
        app(NotificationService::class)->leakageReportFlagged($report, null);

        $this->assertSame(0, $optedOut->notifications()->count());
        $this->assertSame(1, $optedIn->notifications()->count());
    }

    public function test_landing_page_is_limited_to_accessible_modules_and_used_after_login(): void
    {
        $user = $this->userWithRole('inventory-staff');
        $this->actingAs($user);

        Livewire::test(ProfilePage::class)
            ->set('activeTab', 'preferences')
            ->assertDontSee('Production schedule')
            ->set('landingPage', 'production')
            ->call('savePreferences')
            ->assertHasErrors('landing_page')
            ->set('landingPage', 'inventory')
            ->call('savePreferences')
            ->assertHasNoErrors();

        auth()->logout();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('inventory.overview'));
    }

    public function test_stale_landing_page_without_access_falls_back_to_dashboard_on_login(): void
    {
        $user = $this->userWithRole('inventory-staff');
        $user->preferences()->create(['key' => UserPreferenceService::LANDING_PAGE_KEY, 'value' => 'production']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_page_size_preference_applies_to_paginated_tables(): void
    {
        $user = $this->userWithRole('inventory-staff');

        foreach (range(1, 30) as $index) {
            $user->notify(new SystemNotification([
                'type' => 'test',
                'title' => "Alert {$index}",
                'body' => 'Body',
                'url' => '/notifications',
                'severity' => 'info',
                'key' => "test:{$index}",
            ]));
        }

        $this->actingAs($user);
        $this->assertSame(20, Livewire::test(NotificationsPage::class)->viewData('notifications')->perPage());

        Livewire::test(ProfilePage::class)
            ->set('activeTab', 'preferences')
            ->set('pageSize', '15')
            ->call('savePreferences')
            ->assertHasNoErrors()
            ->set('pageSize', '40')
            ->call('savePreferences')
            ->assertHasErrors('page_size');

        $this->assertSame(15, Livewire::test(NotificationsPage::class)->viewData('notifications')->perPage());
    }

    public function test_view_as_session_shows_return_link_and_profile_is_read_only(): void
    {
        $administrator = $this->userWithRole('administrator');
        $target = $this->userWithRole('inventory-staff', ['name' => 'Wanjiru Kamau']);

        $this->actingAs($administrator)
            ->post(route('impersonation.store'), ['user_id' => $target->id]);

        $this->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Viewing as Wanjiru Kamau')
            ->assertSee('Return to my account')
            ->assertSee('action="'.route('impersonation.destroy').'"', false)
            ->assertSee('read-only');

        Livewire::test(ProfilePage::class)
            ->set('name', 'Changed During View As')
            ->call('saveDetails')
            ->assertForbidden();

        $this->assertSame('Wanjiru Kamau', $target->fresh()->name);
    }

    public function test_profile_menu_shows_identity_and_links_without_view_as_line(): void
    {
        $user = $this->userWithRole('inventory-staff');

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertSee('aria-label="Profile menu for '.e($user->name).'"', false)
            ->assertSee('href="'.route('profile.show').'"', false)
            ->assertSee('href="'.route('notifications.index').'"', false)
            ->assertSee('Sign out')
            ->assertDontSee('Return to my account');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(string $roleSlug, array $attributes = []): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->valueOrFail('id'),
            ...$attributes,
        ]);
    }

    private function insertSession(string $id, User $user, string $userAgent = 'Mozilla/5.0 Firefox/131.0', string $ipAddress = '127.0.0.1'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'payload' => '',
            'last_activity' => now()->subMinutes(5)->getTimestamp(),
        ]);
    }
}
