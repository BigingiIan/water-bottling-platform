<?php

namespace Tests\Feature;

use App\Models\DepartmentRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Requests\RequestType;
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

    public function test_forbidden_page_request_access_creates_one_access_request_for_administrators(): void
    {
        $this->travelTo('2026-10-27 09:00:00');
        $administrator = $this->userWithRole('administrator');
        $requester = $this->userWithRole('maintenance-staff', ['name' => 'Juma Otieno']);
        Route::middleware(['web', 'auth', 'module.access:inventory'])->get('/_test/inventory-only', fn () => 'ok');

        $this->actingAs($requester)
            ->get('/_test/inventory-only')
            ->assertForbidden()
            ->assertSee('Request Access')
            ->assertSee('name="module" value="inventory"', false)
            ->assertSee('name="page" value="/_test/inventory-only"', false);

        $this->post(route('access-requests.store'), ['module' => 'inventory', 'page' => '/_test/inventory-only'])
            ->assertRedirect(route('requests.show', 1))
            ->assertSessionHas('status', 'Access request REQ-2026-0001 for Inventory sent to your administrators.');
        $this->post(route('access-requests.store'), ['module' => 'inventory', 'page' => '/_test/inventory-only']);

        $request = DepartmentRequest::query()->sole();
        $this->assertSame(RequestType::AccessRequest, $request->type);
        $this->assertSame(Role::query()->where('slug', 'administrator')->value('id'), $request->to_role_id);
        $this->assertSame($requester->id, $request->from_user_id);
        $this->assertSame([
            ['label' => 'Requested by', 'value' => 'Juma Otieno'],
            ['label' => 'Current role', 'value' => 'Maintenance Staff'],
            ['label' => 'Module', 'value' => 'Inventory'],
            ['label' => 'Page', 'value' => '/_test/inventory-only'],
        ], $request->context_snapshot['fields']);

        $notification = $administrator->notifications()->sole();
        $this->assertSame('request.created', $notification->data['type']);
        $this->assertSame('Juma Otieno requested access: Access to Inventory', $notification->data['title']);
        $this->assertSame(0, $requester->notifications()->count());

        $this->actingAs($administrator)
            ->get(route('requests.show', $request))
            ->assertOk()
            ->assertSee('Review in Roles &amp; Permissions', false)
            ->assertSee('href="'.route('staff.permissions').'"', false);

        // Approval is a manual change in Roles & Permissions: nothing was granted.
        $this->actingAs($requester)->get('/_test/inventory-only')->assertForbidden();
    }

    public function test_access_request_keeps_only_the_path_of_the_attempted_page(): void
    {
        $this->actingAs($this->userWithRole('maintenance-staff'));
        $this->userWithRole('administrator');

        $this->post(route('access-requests.store'), ['module' => 'inventory', 'page' => 'https://evil.example/steal?token=1']);
        $this->post(route('access-requests.store'), ['module' => 'orders', 'page' => 'javascript:alert(1)']);

        $pages = DepartmentRequest::query()->orderBy('id')->get()
            ->map(fn (DepartmentRequest $request): string => collect($request->context_snapshot['fields'])->firstWhere('label', 'Page')['value'])
            ->all();

        $this->assertSame(['/steal', 'Not recorded'], $pages);
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
