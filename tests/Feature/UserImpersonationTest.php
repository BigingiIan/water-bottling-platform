<?php

namespace Tests\Feature;

use App\Exceptions\ViewAsReadOnlyException;
use App\Livewire\ForecastingPage;
use App\Livewire\InviteUserModal;
use App\Livewire\NotificationsPage;
use App\Livewire\OrdersPage;
use App\Livewire\SettingsPage;
use App\Models\Customer;
use App\Models\DepartmentRequest;
use App\Models\ErrorEvent;
use App\Models\Role;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\SettingsService;
use App\Support\Branding;
use App\Support\Impersonation;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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

    public function test_http_writes_are_refused_while_viewing_as_and_nothing_changes(): void
    {
        $this->startViewAs();

        $this->patchJson(route('preferences.sidebar'), ['collapsed' => true])->assertForbidden();
        $this->post(route('access-requests.store'), ['module' => 'orders', 'page' => '/orders'])
            ->assertForbidden()
            ->assertSee('View As is read-only')
            ->assertSee('Return to my account');

        $this->assertDatabaseEmpty('user_preferences');
        $this->assertSame(0, DepartmentRequest::query()->count());
    }

    public function test_returning_to_admin_and_logging_out_still_work_and_are_audited(): void
    {
        [$administrator, $target] = $this->startViewAs();

        $this->post(route('impersonation.destroy'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($administrator);
        $this->assertDatabaseHas('audit_log', ['action' => 'impersonation.started', 'target_user_id' => $target->id]);
        $this->assertDatabaseHas('audit_log', ['action' => 'impersonation.ended', 'target_user_id' => $target->id]);

        $this->post(route('impersonation.store'), ['user_id' => $target->id]);
        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_livewire_writes_are_blocked_dispatch_a_toast_event_and_change_nothing(): void
    {
        $this->startViewAs();

        Livewire::test(OrdersPage::class)
            ->call('openCustomerModal')
            ->set('customerName', 'Blocked Customer')
            ->call('saveCustomer')
            ->assertDispatched('view-as-read-only')
            ->assertOk();

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_livewire_bulk_notification_deletes_are_blocked(): void
    {
        $administrator = $this->userWithRole('administrator');
        $target = $this->userWithRole('administrator');
        $target->notify(new SystemNotification([
            'type' => 'test', 'title' => 'One', 'body' => 'One body', 'url' => '/notifications', 'severity' => 'info', 'key' => 'test:1',
        ]));
        $target->notifications()->update(['read_at' => now()]);
        $this->actingAs($administrator)->post(route('impersonation.store'), ['user_id' => $target->id]);

        Livewire::test(NotificationsPage::class)
            ->call('clearRead')
            ->assertDispatched('view-as-read-only');

        $this->assertSame(1, $target->notifications()->count());
    }

    public function test_livewire_ui_only_actions_still_work_while_viewing_as(): void
    {
        $this->startViewAs();

        Livewire::test(OrdersPage::class)
            ->set('statusFilter', 'pending')
            ->assertSet('statusFilter', 'pending')
            ->call('openCustomerModal')
            ->assertSet('customerModalOpen', true)
            ->call('$refresh')
            ->assertNotDispatched('view-as-read-only');
    }

    public function test_the_database_guard_refuses_writes_unless_writes_are_allowed(): void
    {
        [, $target] = $this->startViewAs();

        $this->assertThrows(fn () => User::query()->whereKey($target->id)->update(['name' => 'Changed']), ViewAsReadOnlyException::class);
        $this->assertThrows(fn () => DB::table('customers')->insert(['name' => 'X']), ViewAsReadOnlyException::class);
        $this->assertThrows(fn () => DB::statement('with x as (select 1) delete from customers'), ViewAsReadOnlyException::class);

        Impersonation::allowingWrites(function () use ($target): void {
            User::query()->whereKey($target->id)->update(['name' => 'Allowed']);
        });

        $this->assertSame('Allowed', $target->fresh()->name);
        $this->assertTrue(Impersonation::blocksWrites());
    }

    public function test_write_target_detection_handles_quoting_and_read_statements(): void
    {
        $this->assertFalse(Impersonation::writeTarget('select * from "users" for update'));
        $this->assertSame('users', Impersonation::writeTarget('update `users` set a = 1'));
        $this->assertSame('sessions', Impersonation::writeTarget('insert into "main"."sessions" ("id") values (?)'));
        $this->assertSame('cache', Impersonation::writeTarget('delete from [cache] where x = 1'));
        $this->assertSame('orders', Impersonation::writeTarget('TRUNCATE TABLE public.orders'));
        $this->assertNull(Impersonation::writeTarget('create index x on y (z)'));
    }

    public function test_session_and_cache_tables_stay_writable_and_pages_render_with_the_database_cache_store(): void
    {
        config(['cache.default' => 'database']);
        $this->startViewAs();

        $this->get(route('dashboard'))->assertOk();
        Cache::put('view-as-probe', 'ok', 60);
        $this->assertSame('ok', Cache::get('view-as-probe'));
        DB::table('sessions')->where('id', 'none')->delete();
    }

    public function test_last_active_at_is_not_touched_while_viewing_as_but_is_otherwise(): void
    {
        [$administrator, $target] = $this->startViewAs();
        $this->assertNull($target->fresh()->last_active_at);

        $this->get(route('dashboard'))->assertOk();
        $this->assertNull($target->fresh()->last_active_at);

        $this->post(route('impersonation.destroy'));
        $this->get(route('dashboard'))->assertOk();
        $this->assertNotNull($administrator->fresh()->last_active_at);
    }

    public function test_writes_are_unaffected_when_not_viewing_as(): void
    {
        $administrator = $this->userWithRole('administrator');

        $this->actingAs($administrator)->patchJson(route('preferences.sidebar'), ['collapsed' => true])->assertSuccessful();

        Livewire::test(OrdersPage::class)
            ->call('openCustomerModal')
            ->set('customerName', 'Real Customer')
            ->call('saveCustomer')
            ->assertNotDispatched('view-as-read-only');

        $this->assertSame(1, Customer::query()->count());
    }

    public function test_the_denial_is_not_recorded_as_an_error_event(): void
    {
        $this->startViewAs();

        $this->post(route('access-requests.store'), [])->assertForbidden();

        $this->assertSame(0, ErrorEvent::query()->count());
    }

    public function test_the_banner_says_view_as_is_read_only(): void
    {
        $this->startViewAs();

        $this->get(route('dashboard'))
            ->assertSee('View As is active')
            ->assertSee('read-only')
            ->assertSee('view-as-read-only.window', false);
    }

    public function test_logo_changes_during_view_as_leave_the_file_in_place(): void
    {
        $administrator = $this->userWithRole('administrator');
        $target = $this->userWithRole('administrator');
        Storage::fake('local');
        Storage::disk('local')->put('branding/logo-live.png', 'png');
        app(SettingsService::class)->set('organisation.logo', 'branding/logo-live.png', $administrator);
        $this->actingAs($administrator)->post(route('impersonation.store'), ['user_id' => $target->id]);

        $this->assertThrows(
            fn () => app(Branding::class)->storeLogo(UploadedFile::fake()->create('new.png', 1), $target),
            ViewAsReadOnlyException::class,
        );

        Livewire::test(SettingsPage::class)->call('removeLogo')->assertDispatched('view-as-read-only');

        $this->assertSame(['branding/logo-live.png'], Storage::disk('local')->files('branding'));
    }

    public function test_the_livewire_upload_endpoint_is_blocked_while_viewing_as(): void
    {
        $this->startViewAs();

        $this->post('/livewire-9367aae7/upload-file', ['files' => [UploadedFile::fake()->create('a.png', 1)]])->assertForbidden();
    }

    public function test_forecast_generation_makes_no_outbound_call_while_viewing_as(): void
    {
        Http::fake();
        $this->startViewAs();

        Livewire::test(ForecastingPage::class)->call('generateForecasts')->assertDispatched('view-as-read-only');

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'predict'));
    }

    public function test_inviting_a_user_sends_no_mail_while_viewing_as(): void
    {
        Mail::fake();
        $this->startViewAs();

        Livewire::test(InviteUserModal::class)
            ->call('openModal')
            ->set('name', 'New Hire')
            ->set('email', 'newhire@example.test')
            ->set('roleId', Role::query()->where('slug', 'production-staff')->value('id'))
            ->call('saveInvite')
            ->assertDispatched('view-as-read-only');

        Mail::assertNothingOutgoing();
        $this->assertDatabaseMissing('users', ['email' => 'newhire@example.test']);
    }

    /**
     * Creates every fixture first, then enters View As (the database guard refuses fixtures afterwards).
     *
     * @return array{0: User, 1: User}
     */
    private function startViewAs(): array
    {
        $administrator = $this->userWithRole('administrator');
        $target = $this->userWithRole('administrator', ['last_active_at' => null]);

        $this->actingAs($administrator)
            ->post(route('impersonation.store'), ['user_id' => $target->id])
            ->assertRedirect(route('dashboard'));

        return [$administrator, $target];
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
