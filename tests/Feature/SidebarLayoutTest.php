<?php

namespace Tests\Feature;

use App\Models\DepartmentRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserPreferenceService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SidebarLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        $this->administrator = User::factory()->create(['role_id' => Role::query()->where('slug', 'administrator')->valueOrFail('id')]);
        app(UserPreferenceService::class)->markWelcomeTourSeen($this->administrator);
    }

    public function test_the_sidebar_renders_expanded_with_every_group_open_by_default(): void
    {
        $this->actingAs($this->administrator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-sidebar-state="expanded"', false)
            ->assertSee('lg:w-[248px]', false)
            ->assertSee('aria-controls="nav-group-production"', false);
    }

    public function test_collapsing_the_sidebar_is_saved_and_rendered_on_the_next_page(): void
    {
        $this->actingAs($this->administrator)
            ->patchJson(route('preferences.sidebar'), ['collapsed' => true])
            ->assertNoContent();

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $this->administrator->id,
            'key' => UserPreferenceService::SIDEBAR_COLLAPSED_KEY,
            'value' => '1',
        ]);

        // Rendered collapsed by the server, so the page never loads wide and then snaps to the rail.
        $page = $this->get(route('orders.index'))->assertOk();
        $page->assertSee('data-sidebar-state="collapsed"', false);
        $this->assertStringContainsString(' lg:w-16', $this->sidebarClass($page));
        $this->assertStringNotContainsString('lg:w-[248px]', $this->sidebarClass($page));

        $this->patchJson(route('preferences.sidebar'), ['collapsed' => false])->assertNoContent();
        $this->get(route('orders.index'))->assertSee('data-sidebar-state="expanded"', false);
    }

    public function test_navigation_groups_remember_being_collapsed(): void
    {
        $this->actingAs($this->administrator);
        $this->patchJson(route('preferences.sidebar'), ['group' => 'finance', 'group_collapsed' => true])->assertNoContent();
        $this->patchJson(route('preferences.sidebar'), ['group' => 'system', 'group_collapsed' => true])->assertNoContent();
        $this->patchJson(route('preferences.sidebar'), ['group' => 'system', 'group_collapsed' => false])->assertNoContent();

        $this->assertSame(['finance'], app(UserPreferenceService::class)->collapsedSidebarGroups($this->administrator->fresh()));

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/aria-controls="nav-group-finance"\s+aria-expanded="false"/', $html);
        $this->assertMatchesRegularExpression('/aria-controls="nav-group-system"\s+aria-expanded="true"/', $html);
    }

    public function test_a_collapsed_group_still_shows_the_active_page(): void
    {
        $this->actingAs($this->administrator);
        $this->patchJson(route('preferences.sidebar'), ['group' => 'finance', 'group_collapsed' => true]);

        $html = $this->get(route('invoices.index'))->assertOk()->getContent();
        $group = $this->between($html, 'id="nav-group-finance"', '</nav>');

        $this->assertMatchesRegularExpression('/<div\s+class=""\s*>\s*<a\s+href="[^"]*\/invoices"\s+data-label="Invoices"\s+aria-current="page"/', $group);
        $this->assertMatchesRegularExpression('/<div\s+[^>]*class="hidden"[^>]*>\s*<a\s+href="[^"]*\/revenue"/', $group);
    }

    public function test_the_collapsed_rail_still_marks_the_active_item_and_shows_badges(): void
    {
        DepartmentRequest::factory()->count(3)->create(['to_role_id' => $this->administrator->role_id]);
        $this->actingAs($this->administrator);
        $this->patchJson(route('preferences.sidebar'), ['collapsed' => true]);
        // In the rail every item shows, even in a group the user collapsed.
        $this->patchJson(route('preferences.sidebar'), ['group' => 'inventory', 'group_collapsed' => true]);

        $html = $this->get(route('requests.index'))->assertOk()->getContent();
        $sidebar = $this->between($html, '<nav aria-label="Main navigation"', '</nav>');

        $this->assertMatchesRegularExpression('/href="[^"]*\/requests"\s+data-label="Requests"[^>]*aria-current="page"/', $sidebar);
        // The label stays for screen readers and the badge moves onto the icon, with its count announced.
        $this->assertMatchesRegularExpression('/class="flex-1 truncate lg:sr-only">Requests</', $sidebar);
        $this->assertMatchesRegularExpression('/data-rail-badge[^>]*class="[^"]*lg:inline-flex[^"]*"\s*>\s*3\s*<span class="sr-only">open<\/span>/', $sidebar);
        $this->assertMatchesRegularExpression('/<div\s+[^>]*class="hidden lg:block"[^>]*>\s*<a\s+href="[^"]*\/orders"/', $sidebar);
    }

    public function test_the_drawer_is_wired_for_keyboard_use(): void
    {
        $html = $this->actingAs($this->administrator)->get(route('dashboard'))->assertOk()->getContent();

        // Opened by the header menu button, focus kept inside while open, Esc closes and returns focus.
        $this->assertStringContainsString('x-ref="menuButton"', $html);
        $this->assertStringContainsString('x-on:click="openDrawer()"', $html);
        $this->assertStringContainsString('x-trap.noscroll="drawerOpen"', $html);
        $this->assertStringContainsString('x-on:keydown.escape.window="closeDrawer()"', $html);
        $this->assertStringContainsString('this.$refs.menuButton?.focus()', $html);
        $this->assertStringContainsString('<span class="sr-only">Close navigation</span>', $html);
        $this->assertStringContainsString('aria-keyshortcuts="["', $html);
        $this->assertStringContainsString('<meta name="csrf-token"', $html);
    }

    public function test_preferences_are_validated_and_need_a_signed_in_user(): void
    {
        $this->patchJson(route('preferences.sidebar'), ['collapsed' => true])->assertUnauthorized();

        $this->actingAs($this->administrator);
        $this->patchJson(route('preferences.sidebar'), ['group' => 'reports', 'group_collapsed' => true])->assertJsonValidationErrors('group');
        $this->patchJson(route('preferences.sidebar'), ['group' => 'finance'])->assertJsonValidationErrors('group_collapsed');
        $this->patchJson(route('preferences.sidebar'), ['collapsed' => 'sideways'])->assertJsonValidationErrors('collapsed');
        $this->assertDatabaseCount('user_preferences', 1);
    }

    public function test_a_stored_preference_follows_the_user_to_another_session(): void
    {
        $this->administrator->preferences()->create(['key' => UserPreferenceService::SIDEBAR_COLLAPSED_KEY, 'value' => '1']);
        $this->administrator->preferences()->create(['key' => UserPreferenceService::SIDEBAR_GROUPS_KEY, 'value' => json_encode(['production', 'not-a-group'])]);

        $html = $this->actingAs($this->administrator)->get(route('dashboard'))->assertOk()->getContent();

        // Unknown group names in the stored list are ignored.
        $this->assertStringContainsString('data-sidebar-state="collapsed"', $html);
        $this->assertMatchesRegularExpression('/aria-controls="nav-group-production"\s+aria-expanded="false"/', $html);
        $this->assertStringContainsString('collapsedGroups: JSON.parse(\'[\u0022production\u0022]\')', $html);
    }

    /**
     * The sidebar's server-rendered class attribute (not the Alpine binding next to it).
     */
    private function sidebarClass(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('/<aside\b[^>]*?\sclass="([^"]*)"/', (string) $response->getContent(), $matches));

        return $matches[1];
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "Could not find {$start}");

        return substr($html, $from, strpos($html, $end, $from) - $from);
    }
}
