<?php

namespace Tests\Feature;

use App\Livewire\HelpCentre;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Services\Help\HelpGuides;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class HelpCentreTest extends TestCase
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

    public function test_help_lists_only_the_modules_the_role_can_access_plus_the_general_guides(): void
    {
        $this->actingAs($this->userWithRole('inventory-staff'));

        $guides = Livewire::test(HelpCentre::class)
            ->assertSee('Getting started')
            ->assertSee('Asking another department for something')
            ->viewData('moduleGuides');

        $this->assertSame(['dashboard', 'inventory', 'orders', 'requests'], array_keys($guides));
        $this->get(route('help'))
            ->assertOk()
            ->assertDontSee('Schedule a production run')
            ->assertDontSee('wire:click="openGuide(\'production\')"', false)
            ->assertDontSee('wire:click="openGuide(\'invoicing\')"', false);
    }

    public function test_a_user_with_no_module_access_sees_only_the_general_guides(): void
    {
        $this->actingAs($this->userWithLevels([]));

        $component = Livewire::test(HelpCentre::class)
            ->assertSee('Getting started')
            ->assertSee('Asking another department for something');

        $this->assertSame([], $component->viewData('moduleGuides'));
        $this->assertSame(['getting-started', 'asking-another-department'], array_keys($component->viewData('generalGuides')));
        $this->assertSame(['none'], array_values(array_unique(array_column($component->viewData('capabilities'), 'level'))));
        $this->get(route('help'))->assertOk();
    }

    public function test_what_you_can_do_is_generated_from_the_role_permissions(): void
    {
        $this->actingAs($this->userWithRole('production-manager'));

        $capabilities = collect(Livewire::test(HelpCentre::class)->viewData('capabilities'))->keyBy('slug');

        $this->assertSame(Module::query()->count(), $capabilities->count());
        $this->assertSame('full', $capabilities['production']['level']);
        $this->assertSame(__('help.capabilities.production.full'), $capabilities['production']['description']);
        $this->assertSame('view', $capabilities['inventory']['level']);
        $this->assertSame(__('help.capabilities.inventory.view'), $capabilities['inventory']['description']);
        $this->assertSame('none', $capabilities['invoicing']['level']);
        $this->assertNull($capabilities['invoicing']['guide']);

        DB::table('role_module_permissions')
            ->where('role_id', auth()->user()->role_id)
            ->where('module_id', Module::query()->where('slug', 'invoicing')->value('id'))
            ->update(['access_level' => 'view']);
        $this->app->forgetScopedInstances();

        $capabilities = collect(Livewire::test(HelpCentre::class)->viewData('capabilities'))->keyBy('slug');
        $this->assertSame('view', $capabilities['invoicing']['level']);
        $this->assertSame('invoicing', $capabilities['invoicing']['guide']);
    }

    public function test_search_only_returns_sections_from_guides_the_user_can_read(): void
    {
        $this->actingAs($this->userWithRole('inventory-staff'));

        $results = Livewire::test(HelpCentre::class)
            ->set('search', 'dispatch')
            ->assertSee('Dispatch an order')
            ->viewData('searchResults');
        $this->assertNotEmpty($results);
        $this->assertSame([], array_values(array_diff(array_unique(array_column($results, 'guide')), ['orders', 'dashboard', 'inventory', 'requests', 'getting-started', 'asking-another-department'])));

        $this->actingAs($this->userWithRole('production-staff'));

        Livewire::test(HelpCentre::class)
            ->set('search', 'mark delivered')
            ->assertDontSee('Dispatch an order')
            ->assertSee('Nothing in your guides matches');

        $this->assertSame([], app(HelpGuides::class)->search(auth()->user(), 'record payment'));
    }

    public function test_a_guide_the_role_cannot_read_falls_back_to_getting_started(): void
    {
        $this->actingAs($this->userWithRole('inventory-staff'));

        Livewire::withQueryParams(['guide' => 'production'])
            ->test(HelpCentre::class)
            ->assertSet('guide', 'getting-started')
            ->assertDontSee('Log production');

        Livewire::test(HelpCentre::class)
            ->call('openGuide', 'inventory')
            ->assertSet('guide', 'inventory')
            ->assertSee('Record a stock count')
            ->call('openGuide', 'users-roles')
            ->assertSet('guide', 'getting-started');
    }

    public function test_page_title_popover_appears_only_for_modules_the_user_can_access(): void
    {
        $this->actingAs($this->userWithRole('inventory-staff'));

        $inventory = Blade::render('<x-page-header title="Inventory" help="inventory" />');
        $production = Blade::render('<x-page-header title="Production" help="production" />');

        $this->assertStringContainsString(e(__('help.popover.pages.inventory.text')), $inventory);
        $this->assertStringContainsString(route('help', ['guide' => 'inventory']), $inventory);
        $this->assertStringNotContainsString(e(__('help.popover.pages.production.text')), $production);
        $this->assertStringNotContainsString(__('help.popover.button'), $production);

        $this->get(route('inventory.overview'))->assertOk()->assertSee(__('help.popover.button'));
    }

    public function test_help_is_linked_from_the_footer_and_the_profile_menu(): void
    {
        $this->actingAs($this->userWithRole('maintenance-staff'));

        $content = $this->get(route('profile.show'))->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(2, substr_count($content, 'href="'.route('help').'"'));
        $this->assertStringContainsString('data-tour="help-link"', $content);
    }

    public function test_every_guide_has_the_expected_structure(): void
    {
        foreach (app(HelpGuides::class)->all() as $guide) {
            $sections = collect($guide['sections']);
            $topLevel = $sections->where('level', 2)->pluck('title')->all();
            $tasks = $sections->where('level', 3)->count();

            $this->assertNotSame('', $guide['summary'], $guide['slug']);
            $this->assertContains('Tasks', $topLevel, $guide['slug']);
            $this->assertContains('Common problems', $topLevel, $guide['slug']);
            $this->assertTrue(in_array('What each access level lets you do', $topLevel, true), $guide['slug']);
            $this->assertGreaterThanOrEqual(3, $tasks, $guide['slug']);
            $this->assertLessThanOrEqual(6, $tasks, $guide['slug']);

            if ($guide['module'] !== null) {
                $this->assertTrue(Module::query()->where('slug', $guide['module'])->exists(), "{$guide['slug']} points at an unknown module.");
                $this->assertIsArray(trans("help.popover.pages.{$guide['module']}"), "{$guide['slug']} has no page popover text.");
            }
        }
    }

    public function test_guides_render_without_raw_html(): void
    {
        foreach (app(HelpGuides::class)->all() as $guide) {
            foreach ($guide['sections'] as $section) {
                $this->assertStringNotContainsString('<script', $section['html']);
            }
        }

        $this->assertStringContainsString('<ol>', collect(app(HelpGuides::class)->all()['orders']['sections'])->firstWhere('title', 'Dispatch an order')['html']);
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
