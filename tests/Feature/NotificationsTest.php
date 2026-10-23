<?php

namespace Tests\Feature;

use App\Livewire\InventoryOverview;
use App\Livewire\NotificationBell;
use App\Livewire\NotificationsPage;
use App\Livewire\ProductionRuns;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionLine;
use App\Models\ProductionLog;
use App\Models\ProductionSchedule;
use App\Models\Role;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Notifications\SystemNotification;
use App\Services\Inventory\StockService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\UserPreferenceService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NotificationsTest extends TestCase
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

    public function test_stock_transition_notifies_once_and_a_repeat_does_not_duplicate(): void
    {
        $actor = $this->userWithRole('inventory-staff');
        $recipient = $this->userWithRole('inventory-staff');
        $stock = $this->createStock(threshold: 100, level: 120);
        $service = app(StockService::class);

        $service->applyMovement($stock, 'sale', -30, null, null, $actor);
        $this->assertSame(1, $recipient->unreadNotifications()->count());
        $this->assertSame('stock:'.$stock->product_id.':low', $recipient->unreadNotifications()->first()->data['key']);

        $service->applyMovement($stock, 'sale', -5, null, null, $actor);
        $service->applyMovement($stock, 'stock_in', 40, null, null, $actor);
        $service->applyMovement($stock, 'sale', -40, null, null, $actor);

        $this->assertSame(1, $recipient->notifications()->count());
        $this->assertSame(0, $actor->notifications()->count());

        $service->applyMovement($stock, 'sale', -40, null, null, $actor);

        $this->assertSame(
            ['stock:'.$stock->product_id.':critical', 'stock:'.$stock->product_id.':low'],
            $recipient->notifications()->get()->pluck('data.key')->sort()->values()->all(),
        );
    }

    public function test_stock_notifications_are_scoped_to_roles_with_inventory_access(): void
    {
        $administrator = $this->userWithRole('administrator');
        $productionManager = $this->userWithRole('production-manager');
        $productionStaff = $this->userWithRole('production-staff');
        $maintenanceStaff = $this->userWithRole('maintenance-staff');
        $stock = $this->createStock(threshold: 100, level: 100);

        app(StockService::class)->applyMovement($stock, 'sale', -10, null, null, null);

        $this->assertSame(1, $administrator->notifications()->count());
        $this->assertSame(1, $productionManager->notifications()->count());
        $this->assertSame(0, $productionStaff->notifications()->count());
        $this->assertSame(0, $maintenanceStaff->notifications()->count());
    }

    public function test_notification_settings_toggles_are_respected(): void
    {
        $administrator = $this->userWithRole('administrator');
        $recipient = $this->userWithRole('inventory-staff');
        $settings = app(SettingsService::class);
        $settings->setMany([
            'notify.low_stock' => false,
            'notify.critical_stock' => false,
            'notify.leakage_flag' => false,
        ], $administrator);
        $stock = $this->createStock(threshold: 100, level: 100);

        app(StockService::class)->applyMovement($stock, 'sale', -60, null, null, $administrator);

        $this->actingAs($administrator);
        Livewire::test(InventoryOverview::class)
            ->set('activeTab', 'leakage')
            ->call('openLeakageReportModal')
            ->set('leakageBatchId', (string) $stock->batch_id)
            ->set('leakageExpectedVolume', '100')
            ->set('leakageActualVolume', '90')
            ->call('saveLeakageReport')
            ->assertHasNoErrors();

        $this->assertSame(0, $recipient->notifications()->count());

        $settings->set('notify.leakage_flag', true, $administrator);
        Livewire::test(InventoryOverview::class)
            ->set('activeTab', 'leakage')
            ->call('openLeakageReportModal')
            ->set('leakageBatchId', (string) $stock->batch_id)
            ->set('leakageExpectedVolume', '100')
            ->set('leakageActualVolume', '90')
            ->call('saveLeakageReport');

        $this->assertSame('critical', $recipient->notifications()->sole()->data['severity']);
        $this->assertSame(0, $administrator->notifications()->count());
    }

    public function test_leakage_flag_notifies_inventory_and_production_roles_only(): void
    {
        $inventoryStaff = $this->userWithRole('inventory-staff');
        $productionStaff = $this->userWithRole('production-staff');
        $maintenanceStaff = $this->userWithRole('maintenance-staff');
        $stock = $this->createStock(threshold: 100, level: 100);
        $this->actingAs($this->userWithRole('administrator'));

        Livewire::test(InventoryOverview::class)
            ->set('activeTab', 'leakage')
            ->call('openLeakageReportModal')
            ->set('leakageBatchId', (string) $stock->batch_id)
            ->set('leakageExpectedVolume', '100')
            ->set('leakageActualVolume', '97')
            ->call('saveLeakageReport');

        $this->assertSame('warning', $inventoryStaff->notifications()->sole()->data['severity']);
        $this->assertSame(1, $productionStaff->notifications()->count());
        $this->assertSame(0, $maintenanceStaff->notifications()->count());
    }

    public function test_production_run_below_target_notifies_full_production_roles_except_the_actor(): void
    {
        $actor = $this->userWithRole('production-staff');
        $productionManager = $this->userWithRole('production-manager');
        $inventoryStaff = $this->userWithRole('inventory-staff');
        $line = ProductionLine::query()->create(['name' => 'Line 01', 'status' => 'active']);
        $run = ProductionSchedule::query()->create([
            'product_sku' => 'AQ-500',
            'production_line_id' => $line->id,
            'scheduled_date' => today()->toDateString(),
            'scheduled_time' => '08:00',
            'target_units' => 1000,
            'status' => 'active',
        ]);
        ProductionLog::query()->create([
            'production_schedule_id' => $run->id,
            'operator_id' => $actor->id,
            'units_logged' => 850,
            'logged_at' => now(),
            'quality_status' => 'passed',
        ]);
        $this->actingAs($actor);

        Livewire::test(ProductionRuns::class)->call('completeRun', $run->id);

        $this->assertSame('production.below_target', $productionManager->notifications()->sole()->data['type']);
        $this->assertSame(0, $actor->notifications()->count());
        $this->assertSame(0, $inventoryStaff->notifications()->count());
    }

    public function test_first_server_error_for_a_fingerprint_notifies_administrators_once(): void
    {
        $administrator = $this->userWithRole('administrator');
        $productionManager = $this->userWithRole('production-manager');
        Route::get('/_test/notification-error', function () {
            throw new RuntimeException('Boom');
        });

        $this->get('/_test/notification-error')->assertStatus(500);
        $this->get('/_test/notification-error')->assertStatus(500);

        $this->assertSame('error.new_group', $administrator->notifications()->sole()->data['type']);
        $this->assertSame(0, $productionManager->notifications()->count());
    }

    public function test_bell_shows_capped_badge_and_latest_eight_notifications(): void
    {
        $user = $this->userWithRole('inventory-staff');

        foreach (range(1, 10) as $index) {
            $this->travel(1)->minutes();
            $user->notify($this->notification("Alert {$index}", "test:{$index}"));
        }

        $this->actingAs($user);

        Livewire::test(NotificationBell::class)
            ->assertSee('aria-label="Notifications, 10 unread"', false)
            ->assertSee('9+')
            ->assertSee('wire:poll.60s', false)
            ->assertSee('Alert 10 body')
            ->assertSee('Alert 3 body')
            ->assertDontSee('Alert 2 body')
            ->set('open', true)
            ->assertDontSee('wire:poll.60s', false);

        $user->unreadNotifications()->update(['read_at' => now()]);

        Livewire::test(NotificationBell::class)
            ->assertSee('aria-label="Notifications, 0 unread"', false)
            ->assertDontSee('9+');
    }

    public function test_clicking_a_notification_marks_it_read_and_redirects_to_its_url(): void
    {
        $user = $this->userWithRole('inventory-staff');
        $user->notify($this->notification('Low stock', 'test:click', '/inventory?tab=stock'));
        $notification = $user->notifications()->sole();
        $this->actingAs($user);

        Livewire::test(NotificationBell::class)
            ->call('openNotification', $notification->id)
            ->assertRedirect('/inventory?tab=stock');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_as_read_only_affects_the_current_user(): void
    {
        $user = $this->userWithRole('inventory-staff');
        $otherUser = $this->userWithRole('inventory-staff');
        $user->notify($this->notification('One', 'test:1'));
        $user->notify($this->notification('Two', 'test:2'));
        $otherUser->notify($this->notification('Other', 'test:3'));
        $this->actingAs($user);

        Livewire::test(NotificationBell::class)
            ->call('markAllAsRead')
            ->assertDispatched('notifications-updated');

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(1, $otherUser->unreadNotifications()->count());
    }

    public function test_notifications_page_filters_marks_read_deletes_and_clears_read(): void
    {
        $user = $this->userWithRole('inventory-staff');
        $user->notify($this->notification('Info alert', 'test:info', severity: 'info'));
        $user->notify($this->notification('Critical alert', 'test:critical', severity: 'critical'));
        $user->notify($this->notification('Warning alert', 'test:warning', severity: 'warning'));
        $infoNotification = $user->notifications()->where('data->key', 'test:info')->sole();
        $warningNotification = $user->notifications()->where('data->key', 'test:warning')->sole();
        $this->actingAs($user);

        $this->get(route('notifications.index'))->assertOk()->assertSee('Critical alert');

        Livewire::test(NotificationsPage::class)
            ->set('severityFilter', 'critical')
            ->assertSee('Critical alert')
            ->assertDontSee('Info alert')
            ->set('severityFilter', '')
            ->call('markAsRead', $infoNotification->id)
            ->set('readFilter', 'unread')
            ->assertDontSee('Info alert')
            ->assertSee('Warning alert')
            ->call('deleteNotification', $warningNotification->id)
            ->call('clearRead')
            ->set('readFilter', 'all')
            ->assertSee('Critical alert')
            ->assertDontSee('Info alert')
            ->assertDontSee('Warning alert');

        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_users_cannot_read_or_change_each_others_notifications(): void
    {
        $owner = $this->userWithRole('inventory-staff');
        $intruder = $this->userWithRole('inventory-staff');
        $owner->notify($this->notification('Private alert', 'test:private'));
        $notification = $owner->notifications()->sole();
        $this->actingAs($intruder);

        $this->get(route('notifications.index'))->assertOk()->assertDontSee('Private alert');
        Livewire::test(NotificationBell::class)->assertDontSee('Private alert');
        Livewire::test(NotificationBell::class)->call('openNotification', $notification->id)->assertNotFound();
        Livewire::test(NotificationsPage::class)->call('markAsRead', $notification->id)->assertNotFound();
        Livewire::test(NotificationsPage::class)->call('deleteNotification', $notification->id)->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);

        $notification->markAsRead();
        Livewire::test(NotificationsPage::class)->call('clearRead');

        $this->assertModelExists($notification);
    }

    public function test_producer_failure_is_logged_and_does_not_break_the_request(): void
    {
        $actor = $this->userWithRole('inventory-staff');
        $this->userWithRole('inventory-staff');
        $stock = $this->createStock(threshold: 100, level: 100);
        $failingSettings = Mockery::mock(SettingsService::class)->makePartial();
        $failingSettings->shouldReceive('get')
            ->with(Mockery::pattern('/^notify\./'), Mockery::any())
            ->andThrow(new RuntimeException('Notification store offline'));
        $this->app->instance(NotificationService::class, new NotificationService($failingSettings, app(UserPreferenceService::class)));
        Log::spy();

        $movement = app(StockService::class)->applyMovement($stock, 'sale', -10, null, null, $actor);

        $this->assertModelExists($movement);
        $this->assertSame(90.0, (float) $stock->fresh()->current_level);
        $this->assertDatabaseCount('notifications', 0);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => $message === 'Failed to create notification.')->once();
    }

    private function userWithRole(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->valueOrFail('id'),
        ]);
    }

    private function createStock(float $threshold, int $level): WarehouseStock
    {
        $product = Product::query()->create([
            'name' => 'Test water',
            'unit_type' => '500ml',
            'reorder_threshold' => $threshold,
        ]);
        $batch = ProductionBatch::query()->create([
            'user_id' => User::factory()->create(['role_id' => Role::query()->where('slug', 'maintenance-staff')->valueOrFail('id')])->id,
            'batch_date' => today()->toDateString(),
            'raw_water_volume' => 100,
            'bottled_volume' => 100,
            'status' => 'completed',
        ]);

        return WarehouseStock::query()->create([
            'batch_id' => $batch->id,
            'product_id' => $product->id,
            'current_level' => $level,
        ]);
    }

    /**
     * @param  'info'|'warning'|'critical'  $severity
     */
    private function notification(string $title, string $key, string $url = '/notifications', string $severity = 'info'): SystemNotification
    {
        return new SystemNotification([
            'type' => 'test',
            'title' => $title,
            'body' => "{$title} body",
            'url' => $url,
            'severity' => $severity,
            'key' => $key,
        ]);
    }
}
