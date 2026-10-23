<?php

namespace Tests\Feature;

use App\Livewire\StaffDirectory;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\User;
use App\Models\WarehouseStock;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SharedLayoutTest extends TestCase
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

    public function test_view_as_lives_in_the_profile_menu_for_administrators_only(): void
    {
        $administrator = $this->userWithRole('administrator');
        $this->userWithRole('production-staff', ['name' => 'Production Tester']);

        $content = $this->actingAs($administrator)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('View as another user', $content);
        $this->assertGreaterThan(
            strpos($content, 'id="profile-menu"'),
            strpos($content, 'id="view-as-user"'),
            'The View As selector should render inside the profile menu.',
        );

        $this->actingAs($this->userWithRole('production-staff'))->get('/dashboard')
            ->assertDontSee('View as another user')
            ->assertDontSee('id="view-as-user"', false);
    }

    public function test_header_has_no_search_box_and_sidebar_uses_heroicons(): void
    {
        $this->actingAs($this->userWithRole('administrator'))->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Search users, roles…')
            ->assertDontSee('📦')
            ->assertDontSee('🔐')
            ->assertSee('<svg', false);
    }

    public function test_page_headers_sit_above_the_card_on_directory_matrix_and_error_logs(): void
    {
        $this->actingAs($this->userWithRole('administrator'));

        $pages = [
            '/staff' => ['staff-directory-heading', 'Staff Directory'],
            '/staff/permissions' => ['permission-matrix-heading', 'Roles &amp; Permissions'],
            '/system/errors' => ['error-logs-heading', 'Error Logs'],
        ];

        foreach ($pages as $uri => [$headingId, $title]) {
            $content = $this->get($uri)->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<header class="flex flex-wrap items-end justify-between gap-3">\s*<div class="min-w-0">\s*<h1\s+id="'.$headingId.'"\s+class="text-\[21px\] font-extrabold tracking-tight text-gray-900">'.preg_quote($title, '/').'<\/h1>/',
                $content,
            );
            $this->assertLessThan(
                strpos($content, '<section aria-labelledby="'.$headingId.'"'),
                strpos($content, '<header class="flex flex-wrap items-end'),
                "{$uri} should render its page header above the card.",
            );
        }
    }

    public function test_zero_stock_shows_out_of_stock_even_without_a_reorder_threshold(): void
    {
        $this->actingAs($this->userWithRole('administrator'));
        $product = Product::query()->create(['name' => 'Empty water', 'unit_type' => '1L', 'reorder_threshold' => 0]);
        WarehouseStock::query()->create([
            'batch_id' => ProductionBatch::query()->create([
                'user_id' => auth()->id(),
                'batch_date' => today()->toDateString(),
                'raw_water_volume' => 100,
                'bottled_volume' => 100,
                'status' => 'completed',
            ])->id,
            'product_id' => $product->id,
            'current_level' => 0,
        ]);

        $this->get(route('inventory.overview'))
            ->assertOk()
            ->assertSee('Out of stock')
            ->assertSee('1 stock line<', false);

        $this->get(route('inventory.overview', ['statusFilter' => 'ok']))->assertSee('0 stock lines');
        $this->get(route('inventory.overview', ['statusFilter' => 'out']))->assertSee('1 stock line<', false);
    }

    public function test_production_runs_subtitle_uses_the_configured_date_format(): void
    {
        $this->travelTo('2026-10-22 09:00:00');
        $this->actingAs($this->userWithRole('administrator'));

        $this->get(route('production.runs'))
            ->assertOk()
            ->assertSee('Open runs for 22 Oct 2026')
            ->assertSee('0 open runs')
            ->assertDontSee('2026-10-22 ·');
    }

    public function test_staff_export_downloads_the_filtered_directory(): void
    {
        $this->travelTo('2026-10-22 09:00:00');
        $administrator = $this->userWithRole('administrator', ['name' => 'Ada Admin', 'email' => 'ada@example.com']);
        $this->userWithRole('production-staff', ['name' => 'Peter Production', 'email' => 'peter@example.com']);
        $this->actingAs($administrator);

        $component = Livewire::test(StaffDirectory::class)
            ->assertDontSee('Columns')
            ->set('search', 'Peter')
            ->call('exportCsv')
            ->assertFileDownloaded('staff-directory-2026-10-22.csv');

        $csv = base64_decode(data_get($component->effects, 'download.content'));

        $this->assertStringContainsString("name,email,role,status,modules,last_active_at\n", $csv);
        $this->assertStringContainsString('"Peter Production",peter@example.com,"Production Staff",active,"Dashboard (view); Production (full)",', $csv);
        $this->assertStringNotContainsString('ada@example.com', $csv);
    }

    public function test_staff_export_requires_users_and_roles_access(): void
    {
        $this->actingAs($this->userWithRole('production-staff'));

        Livewire::test(StaffDirectory::class)->assertForbidden();
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
