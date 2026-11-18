<?php

namespace Tests\Feature;

use App\Livewire\ProfilePage;
use App\Livewire\SettingsPage;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\UserPreferenceService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LandingAndSplashTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        $this->administrator = $this->userWithRole('administrator');
        Storage::fake('local');
    }

    public function test_guests_see_the_branded_landing_page_without_business_data(): void
    {
        app(SettingsService::class)->set('organisation.name', 'Mlima Springs Ltd', $this->administrator);
        Customer::query()->create(['name' => 'Secret Distributor Co', 'customer_type' => 'distributor']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Mlima Springs Ltd')
            ->assertSee('href="'.route('login').'"', false)
            ->assertSeeInOrder(['Production', 'Inventory', 'Orders', 'Forecasting'])
            ->assertSee('One place to plan bottling runs')
            ->assertDontSee('Secret Distributor Co')
            ->assertDontSee('/dashboard', false)
            ->assertDontSee('<img', false)
            ->assertDontSee($this->administrator->email);
    }

    public function test_signed_in_users_go_straight_to_their_landing_page(): void
    {
        $this->actingAs($this->administrator)->get('/')->assertRedirect(route('dashboard'));

        $inventoryStaff = $this->userWithRole('inventory-staff');
        $inventoryStaff->preferences()->create(['key' => UserPreferenceService::LANDING_PAGE_KEY, 'value' => 'inventory']);

        $this->actingAs($inventoryStaff)->get('/')->assertRedirect(route('inventory.overview'));
    }

    public function test_the_splash_shows_once_after_signing_in(): void
    {
        $this->post(route('login.store'), ['email' => $this->administrator->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="app-splash"', false)
            ->assertSee('Preparing your dashboard')
            // It ends on page load or by 800 ms, and is never painted when the OS asks for reduced motion.
            ->assertSee("window.addEventListener('load', finish, { once: true });", false)
            ->assertSee('setTimeout(finish, 640);', false)
            ->assertSee("window.matchMedia('(prefers-reduced-motion: reduce)').matches", false);

        $this->get(route('dashboard'))->assertOk()->assertDontSee('id="app-splash"', false);
        $this->get(route('orders.index'))->assertOk()->assertDontSee('id="app-splash"', false);
    }

    public function test_normal_navigation_never_shows_the_splash(): void
    {
        $this->actingAs($this->administrator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="app-splash"', false)
            ->assertDontSee('Preparing your dashboard');
    }

    public function test_the_splash_is_skipped_when_the_profile_asks_for_reduced_motion(): void
    {
        $this->administrator->preferences()->create(['key' => UserPreferenceService::REDUCE_MOTION_KEY, 'value' => '1']);

        $this->post(route('login.store'), ['email' => $this->administrator->email, 'password' => 'password']);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('id="app-splash"', false)
            ->assertSee('data-reduce-motion', false);

        // Taken all the same, so turning the setting off later can't bring back a stale splash.
        $this->administrator->preferences()->where('key', UserPreferenceService::REDUCE_MOTION_KEY)->delete();
        app(UserPreferenceService::class)->reduceMotion($this->administrator->fresh());
        $this->app->forgetScopedInstances();
        $this->get(route('dashboard'))->assertDontSee('id="app-splash"', false);
    }

    public function test_the_operating_system_reduced_motion_setting_hides_the_splash_in_css(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{\s*\.app-splash \{\s*display: none;/', $css);
    }

    public function test_reduce_motion_is_a_profile_preference(): void
    {
        Livewire::actingAs($this->administrator)
            ->test(ProfilePage::class)
            ->set('activeTab', 'preferences')
            ->assertSee('Reduce motion')
            ->set('reduceMotion', true)
            ->call('savePreferences')
            ->assertHasNoErrors();

        $this->assertTrue(app(UserPreferenceService::class)->reduceMotion($this->administrator->fresh()));

        $this->app->forgetScopedInstances();
        Livewire::actingAs($this->administrator)
            ->test(ProfilePage::class)
            ->set('activeTab', 'preferences')
            ->assertSet('reduceMotion', true)
            ->set('reduceMotion', false)
            ->call('savePreferences');

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $this->administrator->id, 'key' => UserPreferenceService::REDUCE_MOTION_KEY]);
    }

    public function test_administrators_can_upload_a_logo_shown_on_the_landing_page_and_splash(): void
    {
        Livewire::actingAs($this->administrator)
            ->test(SettingsPage::class)
            ->set('logoUpload', $this->png(256, 256))
            ->call('saveLogo')
            ->assertHasNoErrors();

        $path = app(SettingsService::class)->get('organisation.logo');
        $this->assertIsString($path);
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('audit_log', ['action' => 'settings.updated']);

        $logoSrc = 'src="'.route('branding.logo', ['v' => substr(sha1($path), 0, 10)]).'"';
        auth()->logout();
        $this->get('/')->assertOk()->assertSee($logoSrc, false);

        $this->post(route('login.store'), ['email' => $this->administrator->email, 'password' => 'password']);
        $this->assertMatchesRegularExpression('/id="app-splash".*?<img '.preg_quote($logoSrc, '/').'/s', (string) $this->get(route('dashboard'))->getContent());
        auth()->logout();

        $this->get(route('branding.logo'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'");

        Livewire::actingAs($this->administrator)->test(SettingsPage::class)->call('removeLogo');

        Storage::disk('local')->assertMissing($path);
        $this->get(route('branding.logo'))->assertNotFound();
        $this->get('/')->assertDontSee('<img', false);
    }

    public function test_logo_uploads_are_limited_to_small_raster_images(): void
    {
        $component = Livewire::actingAs($this->administrator)->test(SettingsPage::class);

        $component->set('logoUpload', UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'))
            ->call('saveLogo')
            ->assertHasErrors('logoUpload');
        $component->set('logoUpload', $this->png(400, 400, paddingBytes: 400 * 1024))
            ->call('saveLogo')
            ->assertHasErrors('logoUpload');
        $component->set('logoUpload', $this->png(2000, 2000))
            ->call('saveLogo')
            ->assertHasErrors('logoUpload');

        $this->assertSame('', app(SettingsService::class)->get('organisation.logo'));
    }

    public function test_only_users_with_full_settings_access_can_change_the_logo(): void
    {
        Livewire::actingAs($this->userWithRole('production-manager'))
            ->test(SettingsPage::class)
            ->assertForbidden();
    }

    /**
     * A PNG with the given dimensions in its header (GD isn't needed: the image and dimensions rules only read
     * the header). Padding after IEND makes a large file.
     */
    private function png(int $width, int $height, int $paddingBytes = 0): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $bytes = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(str_repeat("\0".str_repeat("\x3b\x82\xf6", 1), 1)))
            .$chunk('IEND', '')
            .str_repeat("\0", $paddingBytes);

        return UploadedFile::fake()->createWithContent('logo.png', $bytes);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $slug)->valueOrFail('id')]);
    }
}
