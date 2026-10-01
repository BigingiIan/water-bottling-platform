<?php

namespace Tests\Feature;

use App\Livewire\StaffDirectory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_directory_filters_users_by_accessible_module(): void
    {
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
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
            ->set('moduleFilter', 'equipment')
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
