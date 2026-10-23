<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccessRequestTest extends TestCase
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

    public function test_forbidden_page_request_access_notifies_administrators_once(): void
    {
        $administrator = $this->userWithRole('administrator');
        $requester = $this->userWithRole('maintenance-staff', ['name' => 'Juma Otieno']);
        Route::middleware(['web', 'auth', 'module.access:inventory'])->get('/_test/inventory-only', fn () => 'ok');

        $this->actingAs($requester)
            ->get('/_test/inventory-only')
            ->assertForbidden()
            ->assertSee('Request Access')
            ->assertSee('name="module" value="inventory"', false);

        $this->post(route('access-requests.store'), ['module' => 'inventory'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Access request for Inventory sent to your administrators.');
        $this->post(route('access-requests.store'), ['module' => 'inventory']);

        $notification = $administrator->notifications()->sole();
        $this->assertSame('access.requested', $notification->data['type']);
        $this->assertSame('Juma Otieno requested access to Inventory', $notification->data['title']);
        $this->assertSame(0, $requester->notifications()->count());

        $this->followingRedirects()
            ->post(route('access-requests.store'), ['module' => 'inventory'])
            ->assertSee('Access request for Inventory sent to your administrators.');
    }

    public function test_access_request_rejects_unknown_modules_and_guests(): void
    {
        $administrator = $this->userWithRole('administrator');

        $this->post(route('access-requests.store'), ['module' => 'inventory'])
            ->assertRedirect(route('login'));

        $this->actingAs($this->userWithRole('maintenance-staff'))
            ->post(route('access-requests.store'), ['module' => 'not-a-module'])
            ->assertSessionHasErrors('module');

        $this->assertSame(0, $administrator->notifications()->count());
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
}
