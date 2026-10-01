<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
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

        Route::middleware(['web', 'auth', 'module.access:inventory'])
            ->get('/_test/inventory-read', fn () => response('ok'));
        Route::middleware(['web', 'auth', 'module.access:inventory'])
            ->post('/_test/inventory-write', fn () => response('ok'));
        Route::get('/_test/server-error', function () {
            throw new RuntimeException('private exception details');
        });
    }

    public function test_none_access_renders_the_module_and_user_role(): void
    {
        $maintenanceRole = Role::query()->where('slug', 'maintenance-staff')->firstOrFail();
        $user = User::factory()->create(['role_id' => $maintenanceRole->id]);
        $this->freezeTime();

        $this->actingAs($user)->get('/_test/inventory-read')
            ->assertForbidden()
            ->assertSee("You don't have access to this")
            ->assertSee('Inventory module · role: Maintenance Staff')
            ->assertSee('Back to Dashboard')
            ->assertSee('Request Access')
            ->assertSee('REF:')
            ->assertSee(now()->format('d M Y, H:i'));
    }

    public function test_view_access_write_attempt_renders_read_only_variant(): void
    {
        $productionManagerRole = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $user = User::factory()->create(['role_id' => $productionManagerRole->id]);

        $this->actingAs($user)->post('/_test/inventory-write')
            ->assertForbidden()
            ->assertSee('This action needs full access')
            ->assertSee('Inventory · your access: View only')
            ->assertSee('← Back to Inventory');
    }

    public function test_missing_page_renders_generic_not_found_without_module_context(): void
    {
        $this->get('/page-that-does-not-exist')
            ->assertNotFound()
            ->assertSee('🧭')
            ->assertSee("This page doesn't exist")
            ->assertSee('Back to Dashboard')
            ->assertSee('Go Back')
            ->assertDontSee('role:')
            ->assertDontSee('Inventory')
            ->assertDontSee('Maintenance Staff');
    }

    public function test_server_error_hides_exception_details_even_when_debug_is_enabled(): void
    {
        config(['app.debug' => true]);

        $this->get('/_test/server-error')
            ->assertServerError()
            ->assertSee('Something went wrong on our end')
            ->assertSee('Try Again')
            ->assertSee('Back to Dashboard')
            ->assertSee('REF:')
            ->assertDontSee('private exception details')
            ->assertDontSee('RuntimeException');
    }

    public function test_error_reference_code_is_generated_fresh_for_each_render(): void
    {
        $role = Role::query()->where('slug', 'maintenance-staff')->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        $firstResponse = $this->get('/_test/inventory-read');
        $secondResponse = $this->get('/_test/inventory-read');
        $firstReferenceCount = preg_match('/REF: ([A-F0-9]{4}-[A-F0-9]{4})/', $firstResponse->getContent(), $firstReference);
        $secondReferenceCount = preg_match('/REF: ([A-F0-9]{4}-[A-F0-9]{4})/', $secondResponse->getContent(), $secondReference);

        $firstResponse->assertForbidden();
        $secondResponse->assertForbidden();
        $this->assertSame(1, $firstReferenceCount);
        $this->assertSame(1, $secondReferenceCount);
        $this->assertNotSame($firstReference[1], $secondReference[1]);
    }
}
