<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\InventoryOverview;
use App\Models\LeakageReport;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\User;
use App\Models\WarehouseStock;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class LeakageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ProductionBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        Http::preventStrayRequests();
        $this->batch = ProductionBatch::query()->create([
            'user_id' => $this->userWithRole('administrator')->id,
            'batch_date' => '2026-10-20',
            'raw_water_volume' => 1000,
            'bottled_volume' => 950,
            'status' => 'completed',
        ]);
    }

    public function test_roles_without_inventory_access_are_forbidden(): void
    {
        $this->actingAs($this->userWithRole('maintenance-staff'))
            ->get(route('inventory.overview', ['tab' => 'leakage']))
            ->assertForbidden();
    }

    public function test_view_access_sees_reports_without_actions_and_write_methods_are_refused(): void
    {
        $report = $this->report('process', 'review');
        $this->actingAs($this->userWithRole('production-manager'));

        Livewire::withQueryParams(['tab' => 'leakage'])
            ->test(InventoryOverview::class)
            ->assertSee('Process loss')
            ->assertDontSee('wire:click="openReviewModal', false)
            ->call('openReviewModal', $report->id, 'resolve')
            ->assertForbidden();

        Livewire::test(InventoryOverview::class)->call('openStockCountModal')->assertForbidden();
        $this->assertSame('open', $report->fresh()->status);
    }

    public function test_full_access_acknowledges_then_resolves_with_a_required_note(): void
    {
        $user = $this->userWithRole('inventory-staff');
        $report = $this->report('stock', 'investigate');
        $this->actingAs($user);

        $component = Livewire::withQueryParams(['tab' => 'leakage'])
            ->test(InventoryOverview::class)
            ->call('openReviewModal', $report->id, 'acknowledge')
            ->set('reviewNote', 'Checking the Bay A1 cage')
            ->call('saveReview')
            ->assertHasNoErrors();

        $report->refresh();
        $this->assertSame('acknowledged', $report->status);
        $this->assertSame($user->id, $report->acknowledged_by);

        $component
            ->call('openReviewModal', $report->id, 'resolve')
            ->set('reviewNote', '')
            ->call('saveReview')
            ->assertHasErrors(['reviewNote' => 'required'])
            ->set('reviewNote', 'Pallet found in dispatch bay; recounted')
            ->call('saveReview')
            ->assertHasNoErrors()
            ->assertSee('Pallet found in dispatch bay; recounted');

        $report->refresh();
        $this->assertSame('resolved', $report->status);
        $this->assertSame($user->id, $report->resolved_by);
        $this->assertNotNull($report->resolved_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'leakage.resolved', 'user_id' => $user->id]);
    }

    public function test_leakage_tab_filters_by_source_severity_and_status(): void
    {
        $process = $this->report('process', 'investigate');
        $stock = $this->report('stock', 'review');
        $this->report('manual', null);
        $stock->update(['status' => 'resolved']);
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::withQueryParams(['tab' => 'leakage'])
            ->test(InventoryOverview::class)
            ->assertSee('3 reports')
            ->set('leakageSourceFilter', 'stock')
            ->assertSee('1 report<', false)
            ->assertSee($stock->explanation)
            ->set('leakageSourceFilter', '')
            ->set('leakageFlagFilter', 'Investigate')
            ->assertSee($process->explanation)
            ->assertDontSee($stock->explanation)
            ->set('leakageFlagFilter', '')
            ->set('leakageStatusFilter', 'resolved')
            ->assertSee('1 report<', false)
            ->assertSee($stock->explanation);
    }

    public function test_stock_count_from_the_inventory_page_raises_a_shrinkage_report(): void
    {
        $user = $this->userWithRole('inventory-staff');
        $product = Product::factory()->create(['unit_type' => '5L', 'name' => 'Bottled Water 5L', 'volume_litres' => '5.000']);
        WarehouseStock::query()->create(['batch_id' => $this->batch->id, 'product_id' => $product->id, 'current_level' => 200, 'warehouse_location' => 'Bay C1']);
        $this->actingAs($user);

        Livewire::test(InventoryOverview::class)
            ->call('openStockCountModal')
            ->set('stockCountProductId', (string) $product->id)
            ->assertSee('Bay C1')
            ->set('stockCountLocation', 'Bay C1')
            ->set('stockCountUnits', '180')
            ->set('stockCountNote', 'Cycle count')
            ->call('saveStockCount')
            ->assertHasNoErrors()
            ->assertSet('stockCountOpen', false);

        $report = LeakageReport::query()->sole();
        $this->assertSame('stock_shrinkage', $report->finding);
        $this->assertSame('investigate', $report->severity);
        $this->assertSame('10.000', $report->loss_percent);
        $this->assertSame(180.0, (float) WarehouseStock::query()->where('product_id', $product->id)->value('current_level'));
    }

    public function test_manual_reports_are_graded_on_save(): void
    {
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::test(InventoryOverview::class)
            ->set('activeTab', 'leakage')
            ->call('openLeakageReportModal')
            ->set('leakageBatchId', (string) $this->batch->id)
            ->set('leakageExpectedVolume', '1000')
            ->set('leakageActualVolume', '970')
            ->call('saveLeakageReport');

        $report = LeakageReport::query()->sole();
        $this->assertSame('manual', $report->source);
        $this->assertSame('manual', $report->detected_by);
        $this->assertSame('review', $report->severity);
        $this->assertSame('3.000', $report->loss_percent);
    }

    public function test_dashboard_card_counts_open_review_and_investigate_reports_only(): void
    {
        $this->report('process', 'review');
        $this->report('stock', 'investigate');
        $this->report('manual', null);
        $this->report('process', 'investigate')->update(['status' => 'resolved']);
        $this->report('stock', 'review')->update(['status' => 'acknowledged']);
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::test(Dashboard::class)
            ->assertSee('2 reports')
            ->assertSee('href="'.route('inventory.overview', ['tab' => 'leakage']).'"', false);
    }

    private function report(string $source, ?string $severity): LeakageReport
    {
        static $sequence = 0;
        $sequence++;

        return LeakageReport::query()->create([
            'batch_id' => $source === 'stock' ? null : $this->batch->id,
            'warehouse_location' => $source === 'stock' ? 'Bay A1' : null,
            'expected_volume' => 1000,
            // Manual reports are graded from their volumes on save: 0.5% stays below review.
            'actual_volume' => $source === 'manual' ? 995 : 960,
            'discrepancy' => $source === 'manual' ? -5 : -40,
            'report_date' => '2026-10-21',
            'source' => $source,
            'finding' => match ($source) {
                'process' => 'process_loss',
                'stock' => 'stock_shrinkage',
                default => 'manual',
            },
            'detected_by' => $source === 'manual' ? 'manual' : 'rule',
            'severity' => $severity,
            'loss_percent' => 4,
            'explanation' => "Explanation {$sequence} for a {$source} report.",
            'detection_key' => $source === 'manual' ? null : "{$source}:test:{$sequence}",
        ]);
    }

    private function userWithRole(string $roleSlug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $roleSlug)->valueOrFail('id')]);
    }
}
