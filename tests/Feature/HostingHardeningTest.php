<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Branding;
use App\Support\ErrorEventRecorder;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

class HostingHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);

        Route::middleware('web')->get('/_test/client', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'url' => url('/somewhere'),
        ]));
        Route::middleware('web')->get('/_test/server-error', function (): void {
            throw new RuntimeException('private exception details');
        });
    }

    public function test_web_pages_carry_the_baseline_security_headers(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_redirects_for_guests_carry_the_security_headers_too(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_headers_a_response_already_set_are_not_replaced(): void
    {
        Storage::fake('local');
        app(Branding::class)->storeLogo($this->png(), $this->administrator());

        $this->get(route('branding.logo'))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        Route::middleware('web')->get('/_test/own-headers', fn () => response('ok')->header('X-Frame-Options', 'DENY'));

        $this->get('/_test/own-headers')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_strict_transport_security_is_sent_only_over_https(): void
    {
        $this->get('http://localhost/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->get('/_test/client', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security')
            ->assertJson(['ip' => '127.0.0.1', 'secure' => false]);
    }

    public function test_forwarded_headers_are_honoured_when_proxies_are_trusted(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $response = $this->get('/_test/client', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
            ->assertJson(['ip' => '203.0.113.9', 'secure' => true]);

        $this->assertStringStartsWith('https://', $response->json('url'));
    }

    public function test_an_empty_trusted_proxies_value_means_none(): void
    {
        $this->assertNull(config('trustedproxy.proxies'));
    }

    public function test_generated_urls_are_https_in_production(): void
    {
        $this->assertStringStartsWith('http://', url('/somewhere'));

        $this->app->detectEnvironment(fn (): string => 'production');
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', url('/somewhere'));

        URL::forceScheme(null);
    }

    public function test_the_server_error_page_still_renders_when_the_error_cannot_be_recorded(): void
    {
        $this->mock(ErrorEventRecorder::class)
            ->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('database is gone'));

        $this->get('/_test/server-error')
            ->assertStatus(500)
            ->assertSee('REF: UNAVAILABLE')
            ->assertDontSee('private exception details')
            ->assertDontSee('database is gone');
    }

    public function test_the_logo_is_stored_on_and_served_from_the_configured_branding_disk(): void
    {
        Storage::fake('local');
        Storage::fake('branding-test');
        config(['filesystems.branding_disk' => 'branding-test']);

        $branding = app(Branding::class);
        $branding->storeLogo($this->png(), $this->administrator());

        $path = $branding->logoPath();

        $this->assertNotNull($path);
        Storage::disk('branding-test')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
        $this->get(route('branding.logo'))->assertOk();
    }

    public function test_crawlers_are_told_to_stay_out(): void
    {
        $this->assertStringContainsString("User-agent: *\nDisallow: /\n", (string) file_get_contents(public_path('robots.txt')));
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('logo.png', (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        ));
    }

    private function administrator(): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', 'administrator')->valueOrFail('id')]);
    }
}
