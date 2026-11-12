<?php

namespace Tests\Feature;

use App\Livewire\ProfilePage;
use App\Livewire\SettingsPage;
use App\Models\Role;
use App\Models\User;
use App\Services\AppearanceService;
use App\Services\SettingsService;
use App\Services\UserPreferenceService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppearancePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        $this->administrator = $this->userWithRole('administrator');
    }

    public function test_the_built_in_default_is_light_ocean(): void
    {
        $this->assertSame(['theme' => 'light', 'profile' => 'ocean'], $this->resolve($this->administrator));
        $this->assertSame(['theme' => 'light', 'profile' => 'ocean'], $this->resolve(null));
    }

    public function test_the_user_choice_wins_over_the_organisation_default_which_wins_over_the_built_in_default(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('appearance.theme', 'dark', $this->administrator);
        $settings->set('appearance.profile', 'forest', $this->administrator);

        $this->assertSame(['theme' => 'dark', 'profile' => 'forest'], $this->resolve($this->administrator));
        $this->assertSame(['theme' => 'dark', 'profile' => 'forest'], $this->resolve(null), 'Guests see the organisation default.');

        $this->administrator->preferences()->create(['key' => UserPreferenceService::THEME_KEY, 'value' => 'system']);
        $this->app->forgetScopedInstances();
        $this->assertSame(['theme' => 'system', 'profile' => 'forest'], $this->resolve($this->administrator), 'Each part falls through on its own.');

        $this->administrator->preferences()->create(['key' => UserPreferenceService::PROFILE_KEY, 'value' => 'high-contrast']);
        $this->app->forgetScopedInstances();
        $this->assertSame(['theme' => 'system', 'profile' => 'high-contrast'], $this->resolve($this->administrator));
    }

    public function test_unknown_stored_values_fall_through_to_the_next_level(): void
    {
        $this->administrator->preferences()->create(['key' => UserPreferenceService::THEME_KEY, 'value' => 'sepia']);
        $this->administrator->preferences()->create(['key' => UserPreferenceService::PROFILE_KEY, 'value' => 'neon']);

        $this->assertSame(['theme' => 'light', 'profile' => 'ocean'], $this->resolve($this->administrator));
    }

    public function test_the_theme_is_on_the_html_element_and_in_the_head_before_first_paint(): void
    {
        $this->administrator->preferences()->create(['key' => UserPreferenceService::THEME_KEY, 'value' => 'dark']);
        $this->administrator->preferences()->create(['key' => UserPreferenceService::PROFILE_KEY, 'value' => 'sunset']);

        $content = (string) $this->actingAs($this->administrator)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('class="theme-dark" data-profile="sunset"', $content);
        $this->assertMatchesRegularExpression('/<style id="theme-tokens">:root \{ color-scheme: dark; [^<]*--c-accent: #f59e57;/', $content);
        // The tokens come before the stylesheet and any script, so nothing paints in the wrong theme.
        $this->assertLessThan(strpos($content, '<link'), strpos($content, 'id="theme-tokens"'));
        $this->assertLessThan(strpos($content, '<script'), strpos($content, 'id="theme-tokens"'));
        $this->assertStringNotContainsString('prefers-color-scheme', $content);
    }

    public function test_system_mode_carries_both_modes_for_the_browser_to_choose(): void
    {
        app(SettingsService::class)->set('appearance.theme', 'system', $this->administrator);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('class="theme-system" data-profile="ocean"', false)
            ->assertSee('<meta name="color-scheme" content="light dark">', false)
            ->assertSee(':root { color-scheme: light;', false)
            ->assertSee('@media (prefers-color-scheme: dark) { :root { color-scheme: dark;', false);
    }

    public function test_public_pages_use_the_organisation_default(): void
    {
        app(SettingsService::class)->set('appearance.profile', 'slate', $this->administrator);
        auth()->logout();

        $this->get('/')->assertOk()->assertSee('data-profile="slate"', false);
        $this->get(route('login'))->assertOk()->assertSee('data-profile="slate"', false);
    }

    public function test_administrators_set_the_organisation_default_on_the_appearance_tab(): void
    {
        Livewire::actingAs($this->administrator)
            ->test(SettingsPage::class)
            ->set('activeTab', 'appearance')
            ->assertSee('Colour profile')
            ->assertSeeHtml('<option value="high-contrast">High contrast</option>')
            ->set('values.appearance_theme', 'dark')
            ->set('values.appearance_profile', 'forest')
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertDispatched('appearance-changed', theme: 'dark', profile: 'forest');

        $this->assertSame(['theme' => 'dark', 'profile' => 'forest'], app(AppearanceService::class)->organisationDefault());
        $this->assertDatabaseHas('audit_log', ['action' => 'settings.updated', 'user_id' => $this->administrator->id]);

        Livewire::actingAs($this->administrator)
            ->test(SettingsPage::class)
            ->set('activeTab', 'appearance')
            ->set('values.appearance_profile', 'purple')
            ->call('saveSettings')
            ->assertHasErrors('values.appearance_profile');
    }

    public function test_the_preview_strip_shows_every_profile_in_light_and_dark(): void
    {
        $component = Livewire::actingAs($this->administrator)
            ->test(SettingsPage::class)
            ->set('activeTab', 'appearance');

        foreach (['ocean', 'forest', 'slate', 'sunset', 'high-contrast'] as $profile) {
            foreach (['light', 'dark'] as $mode) {
                $component->assertSeeHtml('data-preview-profile="'.$profile.'"'."\n".'    data-preview-mode="'.$mode.'"');
            }
        }

        $component->assertSee(['Delivered', 'Low', 'Overdue', 'Scheduled', 'Save', 'Cancel', 'fill-chart-4'], false);
    }

    public function test_people_override_the_organisation_default_in_my_profile_and_can_return_to_it(): void
    {
        app(SettingsService::class)->set('appearance.profile', 'forest', $this->administrator);
        $this->app->forgetScopedInstances();

        Livewire::actingAs($this->administrator)
            ->test(ProfilePage::class)
            ->set('activeTab', 'preferences')
            ->assertSee('Organisation default (Forest)')
            ->set('theme', 'dark')
            ->set('profile', 'high-contrast')
            ->call('savePreferences')
            ->assertHasNoErrors()
            ->assertDispatched('appearance-changed', theme: 'dark', profile: 'high-contrast');

        $this->assertSame(['theme' => 'dark', 'profile' => 'high-contrast'], $this->resolve($this->administrator->fresh()));

        $this->app->forgetScopedInstances();
        Livewire::actingAs($this->administrator)
            ->test(ProfilePage::class)
            ->set('activeTab', 'preferences')
            ->assertSet('theme', 'dark')
            ->set('theme', '')
            ->set('profile', '')
            ->call('savePreferences');

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $this->administrator->id, 'key' => UserPreferenceService::THEME_KEY]);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $this->administrator->id, 'key' => UserPreferenceService::PROFILE_KEY]);
        $this->app->forgetScopedInstances();
        $this->assertSame(['theme' => 'light', 'profile' => 'forest'], $this->resolve($this->administrator->fresh()));
    }

    public function test_my_profile_rejects_unknown_themes_and_profiles(): void
    {
        Livewire::actingAs($this->administrator)
            ->test(ProfilePage::class)
            ->set('activeTab', 'preferences')
            ->set('theme', 'sepia')
            ->set('profile', 'neon')
            ->call('savePreferences')
            ->assertHasErrors(['theme', 'profile']);
    }

    /**
     * @return array{theme: string, profile: string}
     */
    private function resolve(?User $user): array
    {
        return app(AppearanceService::class)->resolve($user);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $slug)->valueOrFail('id')]);
    }
}
