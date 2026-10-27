<?php

namespace Tests\Feature;

use App\Livewire\WelcomeTour;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Services\Help\WelcomeTourSteps;
use App\Services\UserPreferenceService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class WelcomeTourTest extends TestCase
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

    public function test_the_tour_runs_once_after_the_forced_password_change(): void
    {
        $user = $this->userWithRole('inventory-staff', ['name' => 'Wanjiru', 'must_change_password' => true, 'status' => 'pending']);
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertRedirect(route('password.set'));
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id, 'key' => UserPreferenceService::TOUR_KEY]);

        $this->followingRedirects()
            ->post(route('password.set.update'), ['password' => 'a-new-password-123', 'password_confirmation' => 'a-new-password-123'])
            ->assertOk()
            ->assertSee('Welcome to AquaOps, Wanjiru')
            ->assertSee('aria-labelledby="welcome-tour-title"', false);

        $this->assertDatabaseHas('user_preferences', ['user_id' => $user->id, 'key' => UserPreferenceService::TOUR_KEY]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Welcome to AquaOps, Wanjiru')
            ->assertDontSee('aria-labelledby="welcome-tour-title"', false);
    }

    public function test_the_tour_can_be_replayed(): void
    {
        $user = $this->userWithRole('inventory-staff');
        app(UserPreferenceService::class)->markWelcomeTourSeen($user);
        $this->actingAs($user);

        Livewire::test(WelcomeTour::class)
            ->assertSet('isOpen', false)
            ->dispatch('replay-tour')
            ->assertSet('isOpen', true)
            ->assertSee(__('help.tour.steps.notifications.title'))
            ->call('close')
            ->assertSet('isOpen', false);

        $this->get(route('help'))->assertOk()->assertSee(__('help.centre.replay_tour'));
        $this->get(route('profile.show'))->assertOk()->assertSee(__('help.centre.replay_tour'));
    }

    public function test_steps_follow_the_role_and_skip_hidden_items(): void
    {
        $steps = app(WelcomeTourSteps::class);
        $inventory = $this->userWithRole('inventory-staff');

        $this->assertSame(
            ['welcome', 'sidebar', 'requests', 'notifications', 'profile', 'dashboard', 'help'],
            array_column($steps->for($inventory), 'key'),
        );
        $sidebar = collect($steps->for($inventory))->firstWhere('key', 'sidebar');
        $this->assertStringContainsString('Dashboard, Inventory, Orders, Requests', $sidebar['body']);
        $this->assertStringNotContainsString('Production', $sidebar['body']);

        $visitor = $this->userWithLevels([]);
        $keys = array_column($steps->for($visitor), 'key');

        $this->assertSame(['welcome', 'sidebar', 'notifications', 'profile', 'help'], $keys);
        $this->assertGreaterThanOrEqual(5, count($keys));
    }

    public function test_the_tour_does_not_open_while_viewing_as_another_user(): void
    {
        $user = $this->userWithRole('inventory-staff');
        $this->actingAs($user)->withSession(['impersonator_id' => $this->userWithRole('administrator')->id]);

        $this->get(route('dashboard'))->assertOk()->assertDontSee('aria-labelledby="welcome-tour-title"', false);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id, 'key' => UserPreferenceService::TOUR_KEY]);
    }

    public function test_tour_targets_exist_in_the_layout(): void
    {
        $this->actingAs($this->userWithRole('administrator'));

        $content = $this->get(route('dashboard'))->assertOk()->getContent();

        foreach (array_filter(array_column(WelcomeTourSteps::STEPS, 'target')) as $target) {
            $this->assertStringContainsString("data-tour=\"{$target}\"", $content, "Missing tour target {$target}.");
        }
    }

    /**
     * @param  array<string, string>  $levels
     */
    private function userWithLevels(array $levels): User
    {
        $role = Role::query()->create(['name' => 'Visitor', 'slug' => 'visitor']);

        foreach (Module::query()->get(['id', 'slug']) as $module) {
            DB::table('role_module_permissions')->insert([
                'role_id' => $role->id,
                'module_id' => $module->id,
                'access_level' => $levels[$module->slug] ?? 'none',
            ]);
        }

        return User::factory()->create(['role_id' => $role->id]);
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
