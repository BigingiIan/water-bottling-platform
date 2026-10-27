<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\Dashboard\MyRequestsWidget;
use App\Livewire\Dashboard\ReceivablesWidget;
use App\Livewire\Dashboard\RecentActivityWidget;
use App\Livewire\InventoryOverview;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DepartmentRequest;
use App\Models\Invoice;
use App\Models\LeakageReport;
use App\Models\Module;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\WarehouseStock;
use App\Services\Orders\OrderStatus;
use App\Support\Dashboard\DashboardWidgetRegistry;
use App\Support\SettingsDate;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 09:00:00');
        $this->seed([
            RoleSeeder::class,
            ModuleSeeder::class,
            RoleModulePermissionSeeder::class,
        ]);
        Http::preventStrayRequests();
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: string}>
     */
    public static function moduleWidgets(): array
    {
        return [
            'production' => ['production', ["Today's output", 'Open runs'], '"production_schedules"'],
            'inventory' => ['inventory', ['Stock alerts', 'Leakage review'], '"leakage_reports"'],
            'orders' => ['orders', ['Orders today'], 'from "orders"'],
            'invoicing' => ['invoicing', ['Receivables'], '"invoices"'],
            'forecasting' => ['forecasting', ["Tomorrow's forecast vs stock cover"], '"forecast_records"'],
        ];
    }

    /**
     * @param  list<string>  $titles
     */
    #[DataProvider('moduleWidgets')]
    public function test_widgets_are_absent_and_never_queried_without_module_access(string $module, array $titles, string $tableMarker): void
    {
        $everyModule = Module::query()->pluck('slug')->mapWithKeys(fn (string $slug): array => [$slug => 'full'])->all();
        $this->actingAs($this->userWithLevels([...$everyModule, $module => 'none']));

        $queries = $this->queriesDuring(fn () => Livewire::test(Dashboard::class));
        $html = Livewire::test(Dashboard::class)->html();

        foreach ($titles as $title) {
            $this->assertStringNotContainsString(e($title), $html);
        }

        $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, $tableMarker))));
    }

    public function test_widgets_appear_with_view_access(): void
    {
        $this->actingAs($this->userWithLevels(['production' => 'view', 'inventory' => 'view', 'orders' => 'view', 'invoicing' => 'view']));

        Livewire::test(Dashboard::class)
            ->assertSee("Today's output")
            ->assertSee('Stock alerts')
            ->assertSee('Orders today')
            ->assertSee('Receivables')
            ->assertDontSee('Recent activity');
    }

    public function test_recent_activity_is_for_administrators_only(): void
    {
        AuditLog::query()->create(['user_id' => null, 'action' => 'settings.updated', 'table_name' => 'settings']);

        $this->actingAs($this->userWithLevels(['settings' => 'full', 'users-roles' => 'full']));
        $queries = $this->queriesDuring(fn () => Livewire::test(Dashboard::class)->assertDontSee('Recent activity'));
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, '"audit_logs"'))));
        Livewire::test(RecentActivityWidget::class)->assertForbidden();

        $this->actingAs($this->userWithRole('administrator'));
        Livewire::test(Dashboard::class)
            ->assertSee('Recent activity')
            ->assertSee('Settings Updated');
    }

    public function test_widget_components_refuse_users_without_access_and_poll_every_minute(): void
    {
        $this->actingAs($this->userWithLevels(['inventory' => 'view']));

        Livewire::test(ReceivablesWidget::class)->assertForbidden();

        $this->actingAs($this->userWithLevels(['invoicing' => 'view']));

        Livewire::test(ReceivablesWidget::class)
            ->assertOk()
            ->assertSeeHtml('wire:poll.60s');
    }

    public function test_requests_widget_lists_open_requests_for_my_department_and_keeps_the_help_panel(): void
    {
        $this->assertTrue(class_exists(DashboardWidgetRegistry::REQUEST_MODEL));
        $maintenance = Role::query()->where('slug', 'maintenance-staff')->firstOrFail();
        $sender = $this->userWithRole('production-manager');
        DepartmentRequest::factory()->create(['from_user_id' => $sender->id, 'to_role_id' => $maintenance->id, 'subject' => 'Line 2 capper is jamming']);
        DepartmentRequest::factory()->create(['from_user_id' => $sender->id, 'to_role_id' => Role::query()->where('slug', 'inventory-staff')->value('id'), 'subject' => 'Count the 5L stock']);
        $this->actingAs($this->userWithRole('maintenance-staff'));

        Livewire::test(Dashboard::class)
            ->assertSee('My requests')
            ->assertSee('Need something from another team?')
            ->assertSee('Send a request');
        Livewire::test(MyRequestsWidget::class)
            ->assertSee('Line 2 capper is jamming')
            ->assertDontSee('Count the 5L stock');
    }

    public function test_unread_requests_to_my_department_join_the_attention_list(): void
    {
        $inventory = Role::query()->where('slug', 'inventory-staff')->firstOrFail();
        $request = DepartmentRequest::factory()->create([
            'from_user_id' => $this->userWithRole('production-manager')->id,
            'to_role_id' => $inventory->id,
            'subject' => 'Count the 5L stock',
        ]);
        $this->actingAs($this->userWithRole('inventory-staff'));

        $items = Livewire::test(Dashboard::class)->viewData('attentionItems');

        $this->assertSame(["request-{$request->id}"], array_column($items, 'key'));
        $this->assertSame(route('requests.show', $request), $items[0]['url']);
    }

    public function test_roles_get_their_default_layout_and_unmapped_roles_get_every_permitted_widget_by_importance(): void
    {
        $registry = app(DashboardWidgetRegistry::class);
        $productionManager = $this->userWithRole('production-manager');
        $keys = array_map(fn ($widget): string => $widget->key, $registry->visibleFor($productionManager));

        $this->assertSame(['todays-output', 'open-runs', 'stock-alerts', 'leakage-review', 'forecast-cover', 'my-requests', 'notifications'], $keys);
        $this->assertContains('orders-today', array_map(fn (array $entry): string => $entry['widget']->key, $registry->layoutFor($productionManager)));

        $custom = $this->userWithLevels(['orders' => 'view', 'production' => 'view', 'invoicing' => 'view']);

        $this->assertSame(
            ['todays-output', 'orders-today', 'open-runs', 'receivables', 'notifications'],
            array_map(fn ($widget): string => $widget->key, $registry->visibleFor($custom)),
        );
    }

    public function test_customised_layout_is_saved_and_survives_permission_removal(): void
    {
        $user = $this->userWithLevels(['production' => 'view', 'orders' => 'view']);
        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->call('startCustomising')
            ->assertSee('Customise your dashboard')
            ->assertSet('layoutOrder', ['todays-output', 'orders-today', 'open-runs', 'notifications'])
            ->call('moveWidget', 'orders-today', 'up')
            ->call('toggleWidget', 'open-runs')
            ->call('saveLayout')
            ->assertHasNoErrors()
            ->assertSet('customising', false)
            ->assertViewHas('widgets', fn (array $widgets): bool => array_column($widgets, 'key') === ['orders-today', 'todays-output', 'notifications']);

        Livewire::test(Dashboard::class)
            ->assertSeeInOrder(['Orders today', "Today's output", 'Latest notifications'])
            ->assertDontSee('Open runs');

        $stored = json_decode(UserPreference::query()->where('user_id', $user->id)->where('key', DashboardWidgetRegistry::PREFERENCE_KEY)->value('value'), true);
        $this->assertSame(['orders-today', 'todays-output', 'open-runs', 'notifications'], $stored['order']);
        $this->assertSame(['open-runs'], $stored['hidden']);

        $this->setLevel($user->role, 'orders', 'none');

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertDontSee('Orders today')
            ->assertSeeInOrder(["Today's output", 'Latest notifications']);

        $this->setLevel($user->role, 'orders', 'view');

        Livewire::test(Dashboard::class)->assertSeeInOrder(['Orders today', "Today's output"]);
    }

    public function test_saving_after_losing_access_keeps_hidden_choices_for_when_access_returns(): void
    {
        $user = $this->userWithLevels(['production' => 'view', 'orders' => 'view']);
        $this->actingAs($user);
        app(DashboardWidgetRegistry::class)->saveLayout($user, ['orders-today', 'todays-output', 'open-runs', 'notifications'], ['orders-today']);

        $this->setLevel($user->role, 'orders', 'none');
        Livewire::test(Dashboard::class)->call('startCustomising')->call('saveLayout')->assertHasNoErrors();

        $this->setLevel($user->role, 'orders', 'view');
        Livewire::test(Dashboard::class)->assertDontSee('Orders today');
    }

    public function test_widgets_added_later_appear_at_the_end_unless_hidden(): void
    {
        $user = $this->userWithLevels(['production' => 'view', 'orders' => 'view']);
        UserPreference::query()->create([
            'user_id' => $user->id,
            'key' => DashboardWidgetRegistry::PREFERENCE_KEY,
            'value' => json_encode(['order' => ['notifications', 'todays-output', 'retired-widget'], 'hidden' => []]),
        ]);

        $this->assertSame(
            ['notifications', 'todays-output', 'orders-today', 'open-runs'],
            array_map(fn ($widget): string => $widget->key, app(DashboardWidgetRegistry::class)->visibleFor($user)),
        );
    }

    public function test_layout_cannot_include_widgets_the_user_may_not_see(): void
    {
        $this->actingAs($this->userWithLevels(['production' => 'view']));

        Livewire::test(Dashboard::class)
            ->call('startCustomising')
            ->set('layoutOrder', ['todays-output', 'receivables'])
            ->call('saveLayout')
            ->assertHasErrors('layout');

        $this->assertDatabaseMissing('user_preferences', ['key' => DashboardWidgetRegistry::PREFERENCE_KEY]);
    }

    public function test_hiding_every_widget_shows_how_to_restore_them(): void
    {
        $user = $this->userWithLevels(['production' => 'view']);
        $this->actingAs($user);
        app(DashboardWidgetRegistry::class)->saveLayout($user, [], ['todays-output', 'open-runs', 'notifications']);

        Livewire::test(Dashboard::class)
            ->assertSee('All your widgets are hidden.')
            ->call('startCustomising')
            ->call('resetLayout')
            ->assertSee("Today's output");
    }

    public function test_attention_list_orders_by_severity_then_age_and_links_to_each_page(): void
    {
        $this->actingAs($this->userWithRole('administrator'));
        $outOfStock = $this->productWithStock('5L', threshold: 100, level: 0);
        $low = $this->productWithStock('1L', threshold: 100, level: 60);
        $leakage = $this->leakageReport($low, '2026-10-12');
        $order = $this->confirmedOrder($outOfStock, quantity: 20, requestedDate: '2026-10-19');
        $invoice = $this->overdueInvoice('2026-10-15');

        $component = Livewire::test(Dashboard::class)
            ->assertSee('Needs your attention')
            ->assertSee('5L is out of stock')
            ->assertSee("{$order->order_number} can't be dispatched")
            ->assertSee('Short of 20 × 5L')
            ->assertSee('INV-TEST-1 is overdue')
            ->assertSeeHtml('href="'.route('orders.show', $order).'"')
            ->assertSeeHtml('href="'.route('invoices.show', $invoice).'"');

        $this->assertSame(
            ["leakage-{$leakage->id}", "stock-{$outOfStock->id}", "invoice-{$invoice->id}", "order-{$order->id}", "stock-{$low->id}"],
            array_column($component->viewData('attentionItems'), 'key'),
        );
    }

    public function test_attention_list_is_capped_and_follows_module_access(): void
    {
        foreach (range(1, 8) as $index) {
            $this->productWithStock("{$index}0L", threshold: 100, level: 50);
        }
        $this->overdueInvoice('2026-10-15');

        $this->actingAs($this->userWithRole('administrator'));
        $this->assertCount(6, Livewire::test(Dashboard::class)->viewData('attentionItems'));

        $this->actingAs($this->userWithLevels(['invoicing' => 'view']));
        $this->assertSame(['high'], array_column(Livewire::test(Dashboard::class)->viewData('attentionItems'), 'severity'));
    }

    public function test_empty_attention_list_says_nothing_needs_attention(): void
    {
        $this->actingAs($this->userWithLevels(['inventory' => 'view']));

        Livewire::test(Dashboard::class)
            ->assertSee('Nothing needs your attention.')
            ->assertSee('Stock alerts');
    }

    public function test_quick_actions_follow_write_permissions(): void
    {
        $this->actingAs($this->userWithLevels(['production' => 'view', 'orders' => 'view', 'inventory' => 'view']));
        Livewire::test(Dashboard::class)->assertDontSeeHtml('aria-label="Quick actions"');

        $this->actingAs($this->userWithLevels(['production' => 'full', 'orders' => 'view', 'inventory' => 'full']));
        Livewire::test(Dashboard::class)
            ->assertSeeHtml('aria-label="Quick actions"')
            ->assertSee('Log production')
            ->assertSee('Record stock count')
            ->assertSeeHtml('href="'.route('inventory.overview', ['open' => 'stock-count']).'"')
            ->assertDontSee('Create order')
            ->assertDontSee('Invite user');

        $this->actingAs($this->userWithRole('maintenance-staff'));
        Livewire::test(Dashboard::class)->assertDontSeeHtml('aria-label="Quick actions"');
    }

    public function test_stock_count_quick_action_opens_the_form_only_for_inventory_writers(): void
    {
        $this->actingAs($this->userWithLevels(['inventory' => 'full']));
        $this->get(route('inventory.overview', ['open' => 'stock-count']))
            ->assertOk()
            ->assertSee('Record stock count');
        Livewire::withQueryParams(['open' => 'stock-count'])->test(InventoryOverview::class)->assertSet('stockCountOpen', true);

        $this->actingAs($this->userWithLevels(['inventory' => 'view']));
        Livewire::withQueryParams(['open' => 'stock-count'])->test(InventoryOverview::class)
            ->assertOk()
            ->assertSet('stockCountOpen', false);
    }

    public function test_greeting_shows_name_role_and_configured_date(): void
    {
        $this->actingAs($this->userWithRole('inventory-staff', ['name' => 'Wanjiru']));

        Livewire::test(Dashboard::class)
            ->assertSee('Welcome back, Wanjiru')
            ->assertSee('Inventory Staff · '.app(SettingsDate::class)->formatInstantDate(now()));
    }

    public function test_query_count_is_fixed_as_orders_invoices_and_reports_grow(): void
    {
        $this->actingAs($this->userWithRole('administrator'));
        $product = $this->productWithStock('500ml', threshold: 100, level: 10);
        $this->confirmedOrder($product, quantity: 50, requestedDate: '2026-10-20');
        $this->overdueInvoice('2026-10-10');
        $this->leakageReport($product, '2026-10-12');
        AuditLog::query()->create(['user_id' => auth()->id(), 'action' => 'settings.updated', 'table_name' => 'settings']);
        Livewire::test(Dashboard::class);

        $few = count($this->queriesDuring(fn () => Livewire::test(Dashboard::class)));

        foreach (range(1, 5) as $index) {
            $more = $this->productWithStock("{$index}L", threshold: 100, level: $index);
            $this->confirmedOrder($more, quantity: 50, requestedDate: '2026-10-2'.$index);
            $this->overdueInvoice('2026-10-1'.$index);
            $this->leakageReport($more, '2026-10-1'.$index);
            AuditLog::query()->create(['user_id' => auth()->id(), 'action' => 'settings.updated', 'table_name' => 'settings']);
        }

        $this->assertSame($few, count($this->queriesDuring(fn () => Livewire::test(Dashboard::class))));
    }

    /**
     * @param  array<string, string>  $levels
     */
    private function userWithLevels(array $levels, array $attributes = []): User
    {
        $role = Role::query()->create(['name' => 'Custom '.uniqid(), 'slug' => 'custom-'.uniqid()]);

        foreach (Module::query()->get(['id', 'slug']) as $module) {
            DB::table('role_module_permissions')->insert([
                'role_id' => $role->id,
                'module_id' => $module->id,
                'access_level' => $levels[$module->slug] ?? ($module->slug === 'dashboard' ? 'view' : 'none'),
            ]);
        }

        return User::factory()->create(['role_id' => $role->id, ...$attributes]);
    }

    private function setLevel(Role $role, string $moduleSlug, string $level): void
    {
        DB::table('role_module_permissions')
            ->where('role_id', $role->id)
            ->where('module_id', Module::query()->where('slug', $moduleSlug)->value('id'))
            ->update(['access_level' => $level]);

        // Access levels are memoised per request; a new request would start fresh.
        $this->app->forgetScopedInstances();
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

    private function productWithStock(string $sku, int $threshold, int $level): Product
    {
        $product = Product::factory()->create(['name' => 'Bottled Water '.$sku, 'unit_type' => $sku, 'reorder_threshold' => $threshold]);
        WarehouseStock::query()->create(['batch_id' => $this->batch()->id, 'product_id' => $product->id, 'current_level' => $level]);

        return $product;
    }

    private function batch(): ProductionBatch
    {
        return ProductionBatch::query()->create([
            'user_id' => User::query()->value('id') ?? User::factory()->create()->id,
            'batch_date' => today()->toDateString(),
            'raw_water_volume' => 100,
            'bottled_volume' => 100,
            'status' => 'completed',
        ]);
    }

    private function leakageReport(Product $product, string $reportDate): LeakageReport
    {
        return LeakageReport::query()->create([
            'batch_id' => $this->batch()->id,
            'product_id' => $product->id,
            'report_date' => $reportDate,
            'expected_volume' => 100,
            'actual_volume' => 40,
            'discrepancy' => -60,
            'source' => 'stock',
            'severity' => 'investigate',
            'loss_percent' => 60,
            'status' => 'open',
        ]);
    }

    private function confirmedOrder(Product $product, int $quantity, string $requestedDate): Order
    {
        $order = Order::factory()->confirmed()->create(['order_date' => '2026-10-18', 'requested_date' => $requestedDate]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => '45.00']);

        return $order;
    }

    private function overdueInvoice(string $dueDate): Invoice
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered]);

        return Invoice::query()->forceCreate([
            'invoice_number' => 'INV-TEST-'.(Invoice::query()->count() + 1),
            'order_id' => $order->id,
            'orders_invoice_key' => $order->id,
            'customer_id' => $customer->id,
            'status' => 'issued',
            'issue_date' => '2026-09-15',
            'due_date' => $dueDate,
            'prices_include_vat' => false,
            'vat_rate' => '16',
            'subtotal' => '1000.00',
            'vat_amount' => '160.00',
            'total' => '1160.00',
            'amount_paid' => '0.00',
        ]);
    }

    /**
     * @return list<string>
     */
    private function queriesDuring(callable $callback): array
    {
        $this->app->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    }
}
