<?php

namespace Tests\Feature;

use App\Models\ErrorEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
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
        Route::post('/_test/server-error', function () {
            throw new RuntimeException('private exception details');
        });
    }

    public function test_none_access_renders_the_module_and_user_role(): void
    {
        $maintenanceRole = Role::query()->where('slug', 'maintenance-staff')->firstOrFail();
        $user = User::factory()->create(['role_id' => $maintenanceRole->id]);
        $this->freezeTime();

        $response = $this->actingAs($user)->get('/_test/inventory-read');

        $response
            ->assertForbidden()
            ->assertSee("You don't have access to this")
            ->assertSee('Inventory module')
            ->assertSee('Back to Home')
            ->assertSee('Request Access')
            ->assertSee('REF:');

        preg_match('/REF: ([A-F0-9]{4}-[A-F0-9]{4})/', $response->getContent(), $reference);

        $this->assertDatabaseHas('error_events', [
            'reference' => $reference[1],
            'user_id' => $user->id,
            'status_code' => 403,
            'module_slug' => 'inventory',
            'path' => '/_test/inventory-read',
            'method' => 'GET',
            'message' => 'Access denied.',
            'role_slug' => 'maintenance-staff',
        ]);
    }

    public function test_view_access_write_attempt_renders_read_only_variant(): void
    {
        $productionManagerRole = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $user = User::factory()->create(['role_id' => $productionManagerRole->id]);

        $this->actingAs($user)->post('/_test/inventory-write')
            ->assertForbidden()
            ->assertSee('This action needs full access')
            ->assertSee('Inventory · your access: View only')
            ->assertSee('← Back to Home');
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
        $headerSecret = implode('-', ['test', 'private', 'header-value']);

        $response = $this
            ->withHeaders(['X-Api-Token' => $headerSecret])
            ->withCookie('session-secret', 'cookie-secret')
            ->post('/_test/server-error?token=query-secret', [
                'password' => 'body-secret',
                'token' => 'payload-token',
            ]);

        $response
            ->assertServerError()
            ->assertSee('Something went wrong on our end')
            ->assertSee('Try Again')
            ->assertSee('Back to Home')
            ->assertSee('REF:')
            ->assertDontSee('private exception details')
            ->assertDontSee('RuntimeException');

        preg_match('/REF: ([A-F0-9]{4}-[A-F0-9]{4})/', $response->getContent(), $reference);

        $this->assertDatabaseHas('error_events', [
            'reference' => $reference[1],
            'user_id' => null,
            'status_code' => 500,
            'module_slug' => null,
            'path' => '/_test/server-error',
            'method' => 'POST',
            'exception_class' => RuntimeException::class,
            'message' => 'An unexpected server error occurred.',
        ]);
        $event = ErrorEvent::query()->where('reference', $reference[1])->firstOrFail();
        $storedData = implode(' ', [
            $event->reference,
            $event->path,
            $event->method,
            $event->exception_class,
            $event->message,
            $event->exception_message,
            $event->exception_file,
            $event->ip_address,
            json_encode($event->trace),
        ]);
        $this->assertSame('/_test/server-error', $event->path);
        $this->assertSame('private exception details', $event->exception_message);
        $this->assertNotEmpty($event->exception_file);
        $this->assertGreaterThan(0, $event->exception_line);
        $this->assertSame(
            hash('sha256', $event->exception_class."\0".$event->exception_file."\0".$event->exception_line),
            $event->fingerprint,
        );
        $this->assertLessThanOrEqual(15, count($event->trace));
        foreach ($event->trace as $frame) {
            $this->assertArrayNotHasKey('args', $frame);
        }
        $this->assertStringNotContainsString('body-secret', $storedData);
        $this->assertStringNotContainsString('payload-token', $storedData);
        $this->assertStringNotContainsString('query-secret', $storedData);
        $this->assertStringNotContainsString('cookie-secret', $storedData);
        $this->assertStringNotContainsString($headerSecret, $storedData);
    }

    public function test_server_error_view_renders_when_dashboard_route_is_missing(): void
    {
        $routes = new RouteCollection;

        foreach (Route::getRoutes() as $route) {
            if ($route->getName() !== 'dashboard') {
                $routes->add($route);
            }
        }

        app('router')->setRoutes($routes);

        $this->assertNull(Route::getRoutes()->getByName('dashboard'));

        $response = response()->view('errors.500', ['reference' => 'BROK-1234'], 500);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('REF: BROK-1234', $response->getContent());
        $this->assertStringContainsString('href="'.url('/').'"', $response->getContent());
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
        $this->assertDatabaseCount('error_events', 2);
    }

    public function test_error_capture_records_authenticated_user_role_and_ip(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $user = User::factory()->create(['role_id' => $administrator->id]);

        $response = $this->actingAs($user)->get('/_test/server-error?ignored=query');

        $response->assertServerError();
        $event = ErrorEvent::query()->latest('id')->firstOrFail();

        $this->assertSame($user->id, $event->user_id);
        $this->assertSame('administrator', $event->role_slug);
        $this->assertSame('/_test/server-error', $event->path);
        $this->assertSame('127.0.0.1', $event->ip_address);
    }
}
