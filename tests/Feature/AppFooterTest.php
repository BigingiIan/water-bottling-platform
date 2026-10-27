<?php

namespace Tests\Feature;

use App\Livewire\ForecastServiceStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class AppFooterTest extends TestCase
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

        config(['app.version' => '2.4.1']);
        $this->travelTo('2026-10-27 09:00:00');
        Http::preventStrayRequests();
    }

    public function test_authenticated_pages_show_organisation_version_year_and_deferred_forecast_status(): void
    {
        $administrator = $this->userWithRole('administrator');
        app(SettingsService::class)->set('organisation.name', 'Maji Safi Ltd', $administrator);
        $this->actingAs($administrator);

        $content = $this->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Maji Safi Ltd')
            ->assertSee('AquaOps')
            ->assertSee('v2.4.1')
            ->assertSee('&copy; 2026', false)
            ->assertSee('Checking forecast service')
            ->assertDontSee('>Help</a>', false)
            ->getContent();

        $this->assertGreaterThan(strpos($content, '</main>'), strpos($content, '<footer'));
        Http::assertNothingSent();
    }

    public function test_forecast_status_is_hidden_from_roles_without_forecasting_access(): void
    {
        $this->actingAs($this->userWithRole('production-staff'));

        $this->get(route('profile.show'))
            ->assertOk()
            ->assertSee('v2.4.1')
            ->assertDontSee('Checking forecast service');

        Livewire::withoutLazyLoading()->test(ForecastServiceStatus::class)
            ->assertDontSee('Forecast service');

        Http::assertNothingSent();
    }

    public function test_help_link_appears_once_the_help_route_exists(): void
    {
        Route::get('/help', fn () => 'help')->name('help');
        Route::getRoutes()->refreshNameLookups();
        $this->actingAs($this->userWithRole('production-staff'));

        $this->get(route('profile.show'))
            ->assertOk()
            ->assertSee('href="'.url('/help').'"', false);
    }

    public function test_forecast_status_reports_a_reachable_service_and_caches_the_check(): void
    {
        Http::fake(['*/health' => Http::response(['models' => ['500ml' => 'prophet-v1']])]);
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::withoutLazyLoading()->test(ForecastServiceStatus::class)
            ->assertSee('Forecast service reachable');
        Livewire::withoutLazyLoading()->test(ForecastServiceStatus::class)
            ->assertSee('Forecast service reachable');

        Http::assertSentCount(1);
        $this->assertTrue(Cache::get(ForecastServiceStatus::CACHE_KEY));
    }

    public function test_forecast_status_reports_an_unreachable_service_without_breaking(): void
    {
        Http::fake(['*/health' => Http::failedConnection()]);
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::withoutLazyLoading()->test(ForecastServiceStatus::class)
            ->assertOk()
            ->assertSee('Forecast service unreachable');
    }

    public function test_forecast_status_treats_an_unexpected_failure_as_unreachable(): void
    {
        Cache::shouldReceive('remember')->andThrow(new RuntimeException('cache store is down'));
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::withoutLazyLoading()->test(ForecastServiceStatus::class)
            ->assertOk()
            ->assertSee('Forecast service unreachable');
    }

    public function test_sign_in_page_shows_the_reduced_footer(): void
    {
        $administrator = $this->userWithRole('administrator');
        app(SettingsService::class)->set('organisation.name', 'Maji Safi Ltd', $administrator);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Maji Safi Ltd')
            ->assertSee('v2.4.1')
            ->assertSee('&copy; 2026', false)
            ->assertDontSee('forecast service');
    }

    public function test_error_pages_show_the_reduced_footer(): void
    {
        $administrator = $this->userWithRole('administrator');
        app(SettingsService::class)->set('organisation.name', 'Maji Safi Ltd', $administrator);

        $this->get('/a-page-that-does-not-exist')
            ->assertNotFound()
            ->assertSee('<footer class="site-footer">', false)
            ->assertSee('Maji Safi Ltd')
            ->assertSee('AquaOps v2.4.1')
            ->assertSee('&copy; 2026', false);
    }

    public function test_error_page_footer_falls_back_to_the_app_name_when_settings_are_unavailable(): void
    {
        config(['app.name' => 'AquaOps Console']);
        $this->mock(SettingsService::class)
            ->shouldReceive('get')
            ->andThrow(new RuntimeException('database is down'));

        $this->get('/a-page-that-does-not-exist')
            ->assertNotFound()
            ->assertSee('AquaOps Console')
            ->assertSee('AquaOps v2.4.1');
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
