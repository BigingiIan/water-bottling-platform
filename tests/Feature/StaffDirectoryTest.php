<?php

namespace Tests\Feature;

use App\Livewire\StaffDirectory;
use App\Models\AuditLog;
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

class StaffDirectoryTest extends TestCase
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

    public function test_directory_filters_real_users_by_search_role_and_status(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $productionStaff = Role::query()->where('slug', 'production-staff')->firstOrFail();

        $admin = User::factory()->create([
            'name' => 'John Kamau',
            'email' => 'john@example.test',
            'role_id' => $administrator->id,
            'status' => 'active',
        ]);
        $suspended = User::factory()->create([
            'name' => 'Peter Otieno',
            'email' => 'peter@example.test',
            'role_id' => $productionStaff->id,
            'status' => 'suspended',
        ]);

        Livewire::test(StaffDirectory::class)
            ->assertSee($admin->name)
            ->assertSee($suspended->name)
            ->set('roleFilter', (string) $administrator->id)
            ->assertSee($admin->name)
            ->assertDontSee($suspended->name)
            ->set('roleFilter', '')
            ->set('statusFilter', 'suspended')
            ->assertSee($suspended->name)
            ->assertDontSee($admin->name)
            ->set('statusFilter', '')
            ->set('search', 'Administrator')
            ->assertSee($admin->email)
            ->assertDontSee($suspended->email)
            ->set('search', 'john@example.test')
            ->assertSee($admin->name)
            ->assertDontSee($suspended->name);
    }

    public function test_empty_filter_results_offer_to_clear_every_filter(): void
    {
        $productionStaff = Role::query()->where('slug', 'production-staff')->firstOrFail();
        $member = User::factory()->create(['name' => 'Peter Otieno', 'role_id' => $productionStaff->id]);

        Livewire::test(StaffDirectory::class)
            ->set('search', 'nobody-matches-this')
            ->set('statusFilter', 'suspended')
            ->set('moduleFilter', 'maintenance')
            ->assertSee('No staff match these filters.')
            ->assertSee('Clear filters')
            ->assertDontSee($member->name)
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('roleFilter', '')
            ->assertSet('statusFilter', '')
            ->assertSet('moduleFilter', '')
            ->assertSee($member->name)
            ->assertDontSee('Clear filters');
    }

    public function test_detail_panel_shows_role_status_last_active_and_recent_activity(): void
    {
        $this->travelTo('2026-10-22 09:00:00');
        $productionStaff = Role::query()->where('slug', 'production-staff')->firstOrFail();
        $member = User::factory()->create([
            'name' => 'Peter Otieno',
            'role_id' => $productionStaff->id,
            'status' => 'active',
            'last_active_at' => now()->subHours(3),
        ]);
        $bystander = User::factory()->create(['name' => 'Grace Wambui', 'role_id' => $productionStaff->id]);
        AuditLog::query()->create(['user_id' => $member->id, 'action' => 'profile.password_changed', 'table_name' => 'users']);
        AuditLog::query()->create(['user_id' => auth()->id(), 'target_user_id' => $member->id, 'action' => 'user.role_changed', 'table_name' => 'users']);
        AuditLog::query()->create(['user_id' => $bystander->id, 'action' => 'profile.name_changed', 'table_name' => 'users']);

        Livewire::test(StaffDirectory::class)
            ->assertDontSeeHtml('aria-labelledby="staff-panel-heading"')
            ->call('openUser', $member->id)
            ->assertSet('selectedUserId', $member->id)
            ->assertSeeHtml('aria-labelledby="staff-panel-heading"')
            ->assertSee('Production Staff')
            ->assertSee('Active')
            ->assertSee('3 hours ago')
            ->assertSee('Profile Password Changed')
            ->assertSee('User Role Changed')
            ->assertDontSee('Profile Name Changed')
            ->assertSee('Change role')
            ->call('closeUser')
            ->assertSet('selectedUserId', null)
            ->assertDontSeeHtml('aria-labelledby="staff-panel-heading"');
    }

    public function test_detail_panel_opens_from_the_url_and_ignores_unknown_users(): void
    {
        $productionStaff = Role::query()->where('slug', 'production-staff')->firstOrFail();
        $member = User::factory()->create(['name' => 'Peter Otieno', 'role_id' => $productionStaff->id]);

        Livewire::withQueryParams(['user' => $member->id])
            ->test(StaffDirectory::class)
            ->assertSeeHtml('id="staff-panel-heading"');

        Livewire::test(StaffDirectory::class)
            ->call('openUser', 999999)
            ->assertSet('selectedUserId', null);
    }

    public function test_view_only_users_can_open_the_panel_but_see_no_actions(): void
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        DB::table('role_module_permissions')
            ->where('role_id', $productionManager->id)
            ->where('module_id', Module::query()->where('slug', 'users-roles')->value('id'))
            ->update(['access_level' => 'view']);
        $viewer = User::factory()->create(['role_id' => $productionManager->id]);
        $this->actingAs($viewer);

        Livewire::test(StaffDirectory::class)
            ->assertSeeHtml('aria-label="View details for '.e($viewer->name).'"')
            ->assertDontSeeHtml('aria-label="Actions for')
            ->call('openUser', $viewer->id)
            ->assertSeeHtml('id="staff-panel-heading"')
            ->assertDontSee('Change role')
            ->assertDontSee('Reset password');
    }

    public function test_directory_filters_users_by_accessible_module(): void
    {
        // Inventory Staff have no maintenance access; Maintenance Staff have full.
        $productionManager = Role::query()->where('slug', 'inventory-staff')->firstOrFail();
        $maintenanceStaff = Role::query()->where('slug', 'maintenance-staff')->firstOrFail();

        $manager = User::factory()->create([
            'name' => 'Production Manager',
            'role_id' => $productionManager->id,
        ]);
        $maintenance = User::factory()->create([
            'name' => 'Maintenance Staff',
            'role_id' => $maintenanceStaff->id,
        ]);

        Livewire::test(StaffDirectory::class)
            ->set('moduleFilter', 'maintenance')
            ->assertSee($maintenance->email)
            ->assertDontSee($manager->email);
    }

    public function test_directory_paginates_users_six_per_page(): void
    {
        foreach (range(1, 7) as $number) {
            User::factory()->create([
                'name' => sprintf('Staff %02d', $number),
                'email' => sprintf('staff%02d@example.test', $number),
            ]);
        }

        Livewire::test(StaffDirectory::class)
            ->assertSee('Staff 01')
            ->assertDontSee('Staff 07')
            ->call('gotoPage', 2)
            ->assertSee('Staff 07');
    }

    public function test_authenticated_web_requests_update_last_active_at(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $user = User::factory()->create([
            'last_active_at' => null,
            'role_id' => $administrator->id,
        ]);

        $this->actingAs($user)->get('/staff')->assertOk();

        $this->assertNotNull($user->fresh()->last_active_at);
    }

    public function test_staff_directory_route_renders_the_livewire_page(): void
    {
        $response = $this->get('/staff');

        $response->assertOk()
            ->assertSee('Staff Directory')
            ->assertSee('wire:id', false);
    }

    public function test_users_without_users_and_roles_access_are_forbidden_from_directory(): void
    {
        $productionStaff = Role::query()->where('slug', 'production-staff')->firstOrFail();
        $this->actingAs(User::factory()->create(['role_id' => $productionStaff->id]));

        $this->get('/staff')->assertForbidden();
    }
}
