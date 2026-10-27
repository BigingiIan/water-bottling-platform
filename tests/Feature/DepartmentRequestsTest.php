<?php

namespace Tests\Feature;

use App\Livewire\RequestDetail;
use App\Livewire\RequestsPage;
use App\Livewire\SendRequestModal;
use App\Models\AuditLog;
use App\Models\DepartmentRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Requests\RequestService;
use App\Services\Requests\RequestStatus;
use App\Services\SettingsService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DepartmentRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $productionManager;

    private User $inventoryClerk;

    private User $maintenanceTech;

    private User $productionOperator;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-27 09:00:00');
        $this->seed([
            RoleSeeder::class,
            ModuleSeeder::class,
            RoleModulePermissionSeeder::class,
        ]);

        $this->administrator = $this->userWithRole('administrator', ['name' => 'Amina Admin']);
        $this->productionManager = $this->userWithRole('production-manager', ['name' => 'Peter Manager']);
        $this->inventoryClerk = $this->userWithRole('inventory-staff', ['name' => 'Wanjiru Stores']);
        $this->maintenanceTech = $this->userWithRole('maintenance-staff', ['name' => 'Otieno Fixit']);
        $this->productionOperator = $this->userWithRole('production-staff', ['name' => 'Baraka Line']);
    }

    public function test_references_are_sequential_per_year(): void
    {
        $first = $this->sendGeneral($this->productionManager, 'inventory-staff');
        $second = $this->sendGeneral($this->productionManager, 'inventory-staff');
        $this->travelTo('2027-01-02 09:00:00');
        $nextYear = $this->sendGeneral($this->productionManager, 'inventory-staff');

        $this->assertSame(['REQ-2026-0001', 'REQ-2026-0002', 'REQ-2027-0001'], [$first->reference, $second->reference, $nextYear->reference]);
    }

    public function test_sender_and_recipient_department_see_the_request_and_other_departments_do_not(): void
    {
        $request = $this->sendGeneral($this->productionManager, 'inventory-staff', subject: 'Check 1L pallets');
        $otherClerk = $this->userWithRole('inventory-staff');

        $this->actingAs($this->productionManager);
        Livewire::withQueryParams(['tab' => 'sent'])->test(RequestsPage::class)->assertSee('Check 1L pallets');
        Livewire::withQueryParams(['tab' => 'inbox'])->test(RequestsPage::class)->assertDontSee('Check 1L pallets');
        $this->get(route('requests.show', $request))->assertOk()->assertSee('Check 1L pallets');

        $this->actingAs($otherClerk);
        Livewire::withQueryParams(['tab' => 'inbox'])->test(RequestsPage::class)->assertSee('Check 1L pallets');
        $this->get(route('requests.show', $request))->assertOk();

        $this->actingAs($this->maintenanceTech);
        Livewire::withQueryParams(['tab' => 'inbox'])->test(RequestsPage::class)->assertDontSee('Check 1L pallets');
        Livewire::withQueryParams(['tab' => 'all'])->test(RequestsPage::class)
            ->assertSet('activeTab', 'inbox')
            ->assertDontSee('Check 1L pallets');
        $this->get(route('requests.show', $request))->assertNotFound();

        $this->actingAs($this->administrator);
        Livewire::withQueryParams(['tab' => 'all'])->test(RequestsPage::class)->assertSee('Check 1L pallets');
        $this->get(route('requests.show', $request))->assertOk();
    }

    public function test_recipient_sees_the_snapshot_without_access_to_the_module_and_no_live_link(): void
    {
        $order = $this->orderWithItem('1L', 600);
        $request = $this->sendAbout($this->productionManager, 'maintenance-staff', 'order', $order->id);

        $this->actingAs($this->maintenanceTech)->get(route('orders.show', $order))->assertForbidden();

        $this->get(route('requests.show', $request))
            ->assertOk()
            ->assertSee("Order {$order->order_number}")
            ->assertSee('1L × 600')
            ->assertSee('Confirmed')
            ->assertSee($order->customer->name)
            ->assertSee("Snapshot only. Your role can't open this record.", false)
            ->assertDontSee('href="'.route('orders.show', $order).'"', false);
    }

    public function test_live_link_appears_when_the_recipient_can_view_the_record(): void
    {
        $order = $this->orderWithItem('1L', 600);
        $request = $this->sendAbout($this->productionManager, 'inventory-staff', 'order', $order->id);

        $this->actingAs($this->inventoryClerk)
            ->get(route('requests.show', $request))
            ->assertOk()
            ->assertSee('Open record')
            ->assertSee('href="'.route('orders.show', $order).'"', false);
    }

    public function test_snapshot_holds_only_the_safe_fields_and_never_prices(): void
    {
        $order = $this->orderWithItem('1L', 600, unitPrice: '987.65');
        $request = $this->sendAbout($this->productionManager, 'maintenance-staff', 'order', $order->id);

        $this->assertSame(['Customer', 'Items', 'Status', 'Requested for'], array_column($request->context_snapshot['fields'], 'label'));
        $this->assertStringNotContainsString('987', json_encode($request->context_snapshot));
    }

    public function test_a_sender_cannot_attach_a_record_from_a_module_they_cannot_view(): void
    {
        $order = $this->orderWithItem('1L', 600);

        try {
            $this->sendAbout($this->productionOperator, 'inventory-staff', 'order', $order->id);
            $this->fail('Expected the snapshot to be refused.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(0, DepartmentRequest::query()->count());

        $this->actingAs($this->productionOperator);
        Livewire::test(SendRequestModal::class)
            ->call('open', 'order', $order->id)
            ->assertForbidden();
    }

    public function test_send_request_modal_prefills_from_the_record_and_creates_the_request(): void
    {
        $order = $this->orderWithItem('5L', 20);
        $this->actingAs($this->productionManager);

        Livewire::test(SendRequestModal::class)
            ->call('open', 'order', $order->id)
            ->assertSet('type', 'stock_check')
            ->assertSet('subject', "About Order {$order->order_number}")
            ->assertSet('dueDate', '2026-10-29')
            ->assertSee('The department will see')
            ->assertSee('5L × 20')
            ->set('toRoleId', (string) Role::query()->where('slug', 'inventory-staff')->value('id'))
            ->set('priority', 'urgent')
            ->set('body', 'Can we cover this from the Westlands store?')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('sentReference', 'REQ-2026-0001')
            ->assertDispatched('request-sent');

        $request = DepartmentRequest::query()->sole();
        $this->assertSame('order', $request->related_type);
        $this->assertSame($order->id, $request->related_id);
        $this->assertSame("Order {$order->order_number}", $request->context_snapshot['label']);
        $this->assertDatabaseHas('audit_log', ['action' => 'requests.created', 'user_id' => $this->productionManager->id]);
    }

    public function test_default_due_days_come_from_settings_and_a_department_is_required(): void
    {
        app(SettingsService::class)->set('requests.default_due_days', 5, $this->administrator);
        $this->actingAs($this->productionManager);

        Livewire::test(SendRequestModal::class)
            ->call('open')
            ->assertSet('dueDate', '2026-11-01')
            ->set('subject', 'Spare caps')
            ->set('body', 'Please order spare caps.')
            ->call('send')
            ->assertHasErrors(['to_role_id']);
    }

    public function test_only_the_recipient_department_or_an_administrator_changes_the_status(): void
    {
        $request = $this->sendGeneral($this->productionManager, 'inventory-staff');
        $this->actingAs($this->productionManager);

        Livewire::test(RequestDetail::class, ['departmentRequest' => $request])
            ->assertDontSee('Acknowledge')
            ->call('changeStatus', 'acknowledged')
            ->assertForbidden();

        $this->actingAs($this->inventoryClerk);
        Livewire::test(RequestDetail::class, ['departmentRequest' => $request])
            ->assertSee('Acknowledge')
            ->call('changeStatus', 'acknowledged')
            ->call('changeStatus', 'in_progress')
            ->call('changeStatus', 'done')
            ->assertSet('pendingStatus', 'done')
            ->set('resolutionNote', 'Counted: 412 units.')
            ->call('changeStatus', 'done')
            ->assertHasNoErrors();

        $request->refresh();
        $this->assertSame(RequestStatus::Done, $request->status);
        $this->assertSame('Counted: 412 units.', $request->resolution_note);
        $this->assertNotNull($request->resolved_at);
        $this->assertSame(3, AuditLog::query()->where('action', 'requests.status_changed')->count());

        $admin = $this->sendGeneral($this->productionManager, 'inventory-staff');
        $this->actingAs($this->administrator);
        Livewire::test(RequestDetail::class, ['departmentRequest' => $admin])->call('changeStatus', 'acknowledged')->assertHasNoErrors();
        $this->assertSame(RequestStatus::Acknowledged, $admin->refresh()->status);
    }

    public function test_declining_needs_a_reason_and_is_audited(): void
    {
        $request = $this->sendGeneral($this->productionManager, 'inventory-staff');
        $this->actingAs($this->inventoryClerk);

        Livewire::test(RequestDetail::class, ['departmentRequest' => $request])
            ->call('changeStatus', 'declined')
            ->call('changeStatus', 'declined')
            ->assertHasErrors('resolutionNote')
            ->set('resolutionNote', 'That stock is reserved for Naivas.')
            ->call('changeStatus', 'declined')
            ->assertHasNoErrors();

        $this->assertSame(RequestStatus::Declined, $request->refresh()->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'requests.declined', 'user_id' => $this->inventoryClerk->id]);
    }

    public function test_finished_requests_cannot_move_backwards(): void
    {
        $request = $this->sendGeneral($this->productionManager, 'inventory-staff');
        $service = app(RequestService::class);
        $service->changeStatus($this->inventoryClerk, $request, RequestStatus::Done, 'Done');

        $this->expectException(ValidationException::class);
        $service->changeStatus($this->inventoryClerk, $request->refresh(), RequestStatus::InProgress);
    }

    public function test_sender_can_comment_cancel_while_open_and_reopen_a_declined_request_once(): void
    {
        $service = app(RequestService::class);
        $cancellable = $this->sendGeneral($this->productionManager, 'inventory-staff');
        $this->actingAs($this->productionManager);

        Livewire::test(RequestDetail::class, ['departmentRequest' => $cancellable])
            ->set('commentBody', 'Any update?')
            ->call('addComment')
            ->assertHasNoErrors()
            ->assertSee('Any update?')
            ->call('cancelRequest');
        $this->assertSame(RequestStatus::Cancelled, $cancellable->refresh()->status);

        $acknowledged = $this->sendGeneral($this->productionManager, 'inventory-staff');
        $service->changeStatus($this->inventoryClerk, $acknowledged, RequestStatus::Acknowledged);
        Livewire::test(RequestDetail::class, ['departmentRequest' => $acknowledged])
            ->assertDontSee('Cancel request')
            ->call('cancelRequest')
            ->assertForbidden();

        $declined = $this->sendGeneral($this->productionManager, 'inventory-staff');
        $service->changeStatus($this->inventoryClerk, $declined, RequestStatus::Declined, 'Not this week.');
        Livewire::test(RequestDetail::class, ['departmentRequest' => $declined])
            ->assertSee('Reopen (once)')
            ->call('reopenRequest');
        $this->assertSame(RequestStatus::Open, $declined->refresh()->status);
        $this->assertNotNull($declined->reopened_at);

        $service->changeStatus($this->inventoryClerk, $declined, RequestStatus::Declined, 'Still no.');
        Livewire::test(RequestDetail::class, ['departmentRequest' => $declined->refresh()])
            ->assertDontSee('Reopen (once)')
            ->call('reopenRequest')
            ->assertForbidden();
    }

    public function test_other_departments_cannot_comment_or_act_on_a_thread(): void
    {
        $request = $this->sendGeneral($this->productionManager, 'inventory-staff');
        $service = app(RequestService::class);

        foreach ([
            fn () => $service->comment($this->maintenanceTech, $request, 'Hello'),
            fn () => $service->changeStatus($this->maintenanceTech, $request, RequestStatus::Acknowledged),
            fn () => $service->cancel($this->inventoryClerk, $request),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected the action to be refused.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertSame(0, $request->comments()->count());
    }

    public function test_comments_are_append_only(): void
    {
        $request = $this->sendGeneral($this->productionManager, 'inventory-staff');
        $comment = app(RequestService::class)->comment($this->productionManager, $request, 'First');

        $this->expectException(\LogicException::class);
        $comment->update(['body' => 'Edited']);
    }

    public function test_notifications_go_to_the_department_then_back_to_the_sender_without_duplicates(): void
    {
        $otherClerk = $this->userWithRole('inventory-staff');
        $request = $this->sendGeneral($this->productionManager, 'inventory-staff', subject: 'Check 1L pallets');

        $this->assertSame(['request.created'], $this->inventoryClerk->notifications()->pluck('data')->pluck('type')->all());
        $this->assertSame(1, $otherClerk->notifications()->count());
        $this->assertSame(0, $this->productionManager->notifications()->count());
        $this->assertSame(0, $this->maintenanceTech->notifications()->count());

        app(NotificationService::class)->requestCreated($request, $this->productionManager);
        $this->assertSame(1, $this->inventoryClerk->notifications()->count());

        $service = app(RequestService::class);
        $service->changeStatus($this->inventoryClerk, $request, RequestStatus::Acknowledged);
        $service->comment($this->inventoryClerk, $request, 'On it.');
        $this->assertEqualsCanonicalizing(['request.status_changed', 'request.commented'], $this->productionManager->notifications()->get()->pluck('data.type')->all());

        $service->assignToSelf($this->inventoryClerk, $request);
        $service->comment($this->productionManager, $request->refresh(), 'Thanks!');
        $this->assertSame(1, $otherClerk->notifications()->count());
        $this->assertEqualsCanonicalizing(['request.created', 'request.commented'], $this->inventoryClerk->notifications()->get()->pluck('data.type')->all());
    }

    public function test_overdue_command_notifies_the_department_once(): void
    {
        $overdue = $this->sendGeneral($this->productionManager, 'inventory-staff', dueDate: '2026-10-28');
        $done = $this->sendGeneral($this->productionManager, 'inventory-staff', dueDate: '2026-10-28');
        app(RequestService::class)->changeStatus($this->inventoryClerk, $done, RequestStatus::Done, 'Done');
        $this->sendGeneral($this->productionManager, 'inventory-staff', dueDate: '2026-11-15');
        $this->travelTo('2026-10-30 08:05:00');

        $this->artisan('requests:notify-overdue')->expectsOutput('Checked 1 overdue request.')->assertSuccessful();
        $this->artisan('requests:notify-overdue')->assertSuccessful();

        $overdueNotifications = $this->inventoryClerk->notifications()->get()->where('data.type', 'request.overdue');
        $this->assertCount(1, $overdueNotifications);
        $this->assertSame("request:{$overdue->id}:overdue", $overdueNotifications->first()->data['key']);
    }

    public function test_requests_page_filters_by_status_type_priority_and_overdue(): void
    {
        $this->sendGeneral($this->productionManager, 'inventory-staff', subject: 'Normal general', dueDate: '2026-10-28');
        $this->sendGeneral($this->productionManager, 'inventory-staff', subject: 'Urgent correction', priority: 'urgent', type: 'data_correction', dueDate: '2026-11-20');
        $done = $this->sendGeneral($this->productionManager, 'inventory-staff', subject: 'Finished one');
        app(RequestService::class)->changeStatus($this->inventoryClerk, $done, RequestStatus::Done, 'Done');
        $this->travelTo('2026-10-30 09:00:00');
        $this->actingAs($this->inventoryClerk);

        Livewire::test(RequestsPage::class)
            ->assertSee('Normal general')
            ->assertSee('Urgent correction')
            ->assertDontSee('Finished one')
            ->set('statusFilter', 'done')
            ->assertSee('Finished one')
            ->assertDontSee('Normal general')
            ->set('statusFilter', '')
            ->set('priorityFilter', 'urgent')
            ->assertSee('Urgent correction')
            ->assertDontSee('Normal general')
            ->set('priorityFilter', '')
            ->set('typeFilter', 'data_correction')
            ->assertSee('Urgent correction')
            ->assertDontSee('Finished one')
            ->set('typeFilter', '')
            ->set('overdueOnly', true)
            ->assertSee('Normal general')
            ->assertDontSee('Urgent correction');
    }

    public function test_sidebar_shows_the_open_request_count_for_my_department(): void
    {
        $this->sendGeneral($this->productionManager, 'inventory-staff');
        $this->sendGeneral($this->productionManager, 'inventory-staff');

        $content = $this->actingAs($this->inventoryClerk)->get(route('requests.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Requests<\/span>\s*<span[^>]*>\s*2\s*<span class="sr-only">open<\/span>/', $content);
    }

    public function test_send_request_buttons_appear_on_records_for_users_who_can_send(): void
    {
        $order = $this->orderWithItem('1L', 10);

        $this->actingAs($this->inventoryClerk)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee("relatedType: 'order', relatedId: {$order->id}", false);

        DB::table('role_module_permissions')
            ->where('role_id', $this->inventoryClerk->role_id)
            ->where('module_id', DB::table('modules')->where('slug', 'requests')->value('id'))
            ->update(['access_level' => 'view']);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee("relatedType: 'order'", false);
    }

    private function sendGeneral(User $sender, string $roleSlug, string $subject = 'Please help', string $priority = 'normal', string $type = 'general', ?string $dueDate = null): DepartmentRequest
    {
        return app(RequestService::class)->create($sender, [
            'type' => $type,
            'subject' => $subject,
            'body' => 'Details of what is needed.',
            'priority' => $priority,
            'to_role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'due_date' => $dueDate,
        ]);
    }

    private function sendAbout(User $sender, string $roleSlug, string $relatedType, int $relatedId): DepartmentRequest
    {
        return app(RequestService::class)->create($sender, [
            'type' => 'stock_check',
            'subject' => 'About a record',
            'body' => 'Please look at this.',
            'priority' => 'normal',
            'to_role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'related_type' => $relatedType,
            'related_id' => $relatedId,
        ]);
    }

    private function orderWithItem(string $sku, int $quantity, string $unitPrice = '45.00'): Order
    {
        $product = Product::factory()->create(['unit_type' => $sku, 'name' => "Bottled Water {$sku}"]);
        $order = Order::factory()->confirmed()->create();
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]);

        return $order;
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
