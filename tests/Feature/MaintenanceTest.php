<?php

namespace Tests\Feature;

use App\Livewire\CreateScheduleModal;
use App\Livewire\Dashboard;
use App\Livewire\Maintenance\EquipmentDetail;
use App\Livewire\Maintenance\WorkOrderDetail;
use App\Livewire\RequestDetail;
use App\Models\Equipment;
use App\Models\MaintenancePlan;
use App\Models\ProductionLine;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Maintenance\LineStatus;
use App\Services\Maintenance\MaintenanceService;
use App\Services\Requests\RequestService;
use App\Services\Requests\RequestStatus;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    private User $productionManager;

    private ProductionLine $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-28 09:00:00');
        $this->seed([RoleSeeder::class, ModuleSeeder::class, RoleModulePermissionSeeder::class]);
        $this->technician = $this->userWithRole('maintenance-staff', ['name' => 'Otieno Fixit']);
        $this->productionManager = $this->userWithRole('production-manager', ['name' => 'Peter Manager']);
        $this->line = ProductionLine::query()->create(['name' => 'Line 01', 'status' => 'active']);
    }

    public function test_permissions_give_maintenance_and_admin_full_production_view_and_others_none(): void
    {
        $equipment = Equipment::factory()->create(['production_line_id' => $this->line->id]);

        $this->actingAs($this->technician)->get(route('maintenance.equipment.index'))->assertOk()->assertSee('Add equipment');
        $this->actingAs($this->userWithRole('administrator'))->get(route('maintenance.work-orders.index'))->assertOk();

        $this->actingAs($this->productionManager)->get(route('maintenance.equipment.show', $equipment))->assertOk()->assertDontSee('Report a breakdown');
        Livewire::test(EquipmentDetail::class, ['equipment' => $equipment])->call('openBreakdown')->assertForbidden();

        $this->actingAs($this->userWithRole('inventory-staff'))->get(route('maintenance.equipment.index'))->assertForbidden();

        try {
            app(MaintenanceService::class)->setEquipmentStatus($this->productionManager, $equipment, 'out_of_service');
            $this->fail('View access must not change equipment.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_work_order_references_are_sequential_per_year(): void
    {
        $equipment = Equipment::factory()->create();
        $service = app(MaintenanceService::class);
        $input = ['type' => 'corrective', 'priority' => 'normal', 'description' => 'Leaking seal'];

        $first = $service->reportBreakdown($this->technician, $equipment, $input);
        $second = $service->reportBreakdown($this->technician, $equipment, $input);
        $this->travelTo('2027-01-03 09:00:00');
        $nextYear = $service->reportBreakdown($this->technician, $equipment, $input);

        $this->assertSame(['WO-2026-0001', 'WO-2026-0002', 'WO-2027-0001'], [$first->reference, $second->reference, $nextYear->reference]);
    }

    public function test_plans_compute_next_due_and_completing_a_preventive_work_order_reschedules_the_plan(): void
    {
        $plan = MaintenancePlan::factory()->create(['every_days' => 30, 'first_due_on' => '2026-10-29']);
        $this->assertSame('2026-10-29', $plan->next_due_on->toDateString(), 'Never done: due on the first due date.');

        $plan->update(['last_done_on' => '2026-09-30']);
        $this->assertSame('2026-10-30', $plan->refresh()->next_due_on->toDateString(), 'Last done plus the interval.');

        $workOrder = app(MaintenanceService::class)->generateDueWorkOrders()['created'][0];
        $workOrder = WorkOrder::query()->where('reference', $workOrder)->sole();
        $this->travelTo('2026-10-30 15:00:00');

        app(MaintenanceService::class)->complete($this->technician, $workOrder, [
            'downtime_minutes' => 45,
            'parts_used' => 'RO membrane',
            'cost_kes' => '12500',
            'checklist_results' => [true, true, false],
            'completion_notes' => 'Done',
        ]);

        $plan->refresh();
        $this->assertSame('2026-10-30', $plan->last_done_on->toDateString());
        $this->assertSame('2026-11-29', $plan->next_due_on->toDateString());
        $this->assertSame([true, true, false], $workOrder->refresh()->checklist_results);
        $this->assertSame(45, $workOrder->downtime_minutes);
        $this->assertDatabaseHas('audit_log', ['action' => 'maintenance.work_order_completed', 'user_id' => $this->technician->id]);
    }

    public function test_the_generator_creates_one_work_order_per_due_date_and_is_idempotent(): void
    {
        $dueSoon = MaintenancePlan::factory()->create(['first_due_on' => '2026-10-30']);
        $overdue = MaintenancePlan::factory()->create(['first_due_on' => '2026-10-20', 'assigned_role_id' => $this->technician->role_id]);
        MaintenancePlan::factory()->create(['first_due_on' => '2026-11-15']);
        MaintenancePlan::factory()->create(['first_due_on' => '2026-10-29', 'active' => false]);

        $this->artisan('maintenance:generate-work-orders')
            ->expectsOutputToContain('Created 2 work orders')
            ->expectsOutputToContain('1 preventive plan overdue.')
            ->assertSuccessful();
        $this->artisan('maintenance:generate-work-orders')->expectsOutputToContain('Created 0 work orders')->assertSuccessful();

        $this->assertSame(2, WorkOrder::query()->count());
        $this->assertSame('urgent', WorkOrder::query()->where('maintenance_plan_id', $overdue->id)->value('priority'));
        $this->assertSame('2026-10-30', WorkOrder::query()->where('maintenance_plan_id', $dueSoon->id)->sole()->due_on->toDateString());
        $this->assertSame(1, $this->technician->notifications()->get()->where('data.type', 'maintenance.plan_overdue')->count(), 'Overdue reminders are deduplicated.');
    }

    public function test_a_new_due_date_after_completion_gets_its_own_work_order(): void
    {
        $plan = MaintenancePlan::factory()->create(['first_due_on' => '2026-10-29', 'every_days' => 3]);
        $service = app(MaintenanceService::class);
        $workOrder = WorkOrder::query()->where('reference', $service->generateDueWorkOrders()['created'][0])->sole();
        $service->complete($this->technician, $workOrder, ['downtime_minutes' => 0]);

        $this->assertSame('2026-10-31', $plan->refresh()->next_due_on->toDateString());
        $this->assertSame([], $service->generateDueWorkOrders()['created'], 'Not within two days yet.');
        $this->travelTo('2026-10-29 06:00:00');
        $this->assertCount(1, $service->generateDueWorkOrders()['created']);
        $this->assertSame(['2026-10-29', '2026-10-31'], WorkOrder::query()->orderBy('id')->get()->map(fn (WorkOrder $order): string => $order->due_on->toDateString())->all());
    }

    public function test_a_cancelled_preventive_work_order_is_raised_again_while_the_plan_is_active(): void
    {
        $plan = MaintenancePlan::factory()->create(['first_due_on' => '2026-10-29']);
        $service = app(MaintenanceService::class);
        $first = WorkOrder::query()->where('reference', $service->generateDueWorkOrders()['created'][0])->sole();

        $service->cancel($this->technician, $first, 'Raised against the wrong shift.');
        $created = $service->generateDueWorkOrders()['created'];

        $this->assertCount(1, $created, 'The task is still due, so it comes back.');
        $this->assertSame([], $service->generateDueWorkOrders()['created'], 'Never two open work orders for one plan.');
        $this->assertSame(['cancelled', 'open'], WorkOrder::query()->where('maintenance_plan_id', $plan->id)->orderBy('id')->pluck('status')->all());

        $plan->update(['active' => false]);
        $service->cancel($this->technician, WorkOrder::query()->where('reference', $created[0])->sole(), 'Plan retired.');
        $this->assertSame([], $service->generateDueWorkOrders()['created'], 'Deactivating the plan stops it.');
    }

    public function test_a_short_cycle_plan_completed_early_is_not_stranded_on_the_same_due_date(): void
    {
        $plan = MaintenancePlan::factory()->create(['first_due_on' => '2026-10-30', 'every_days' => 2]);
        $service = app(MaintenanceService::class);
        $first = WorkOrder::query()->where('reference', $service->generateDueWorkOrders()['created'][0])->sole();

        // Done two days early: with a two-day cycle the task falls due again on the very same date.
        $service->complete($this->technician, $first, ['downtime_minutes' => 0]);
        $this->assertSame('2026-10-30', $plan->refresh()->next_due_on->toDateString());

        $second = $service->generateDueWorkOrders()['created'];
        $this->assertCount(1, $second);
        $this->assertSame('2026-10-30', WorkOrder::query()->where('reference', $second[0])->sole()->due_on->toDateString());
        $this->assertSame([], $service->generateDueWorkOrders()['created']);
    }

    public function test_work_orders_are_only_assigned_to_active_people_with_maintenance_access(): void
    {
        MaintenancePlan::factory()->create(['first_due_on' => '2026-10-29']);
        $service = app(MaintenanceService::class);
        $workOrder = WorkOrder::query()->where('reference', $service->generateDueWorkOrders()['created'][0])->sole();

        foreach ([$this->productionManager->id, 999999] as $notAssignable) {
            try {
                $service->assign($this->technician, $workOrder, $notAssignable);
                $this->fail('Expected the assignment to be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('assigned_to', $exception->errors());
            }
        }

        $this->assertSame($this->technician->id, $service->assign($this->technician, $workOrder, $this->technician->id)->assigned_to);
        $this->assertNull($service->assign($this->technician, $workOrder, null)->assigned_to);
    }

    public function test_completing_asks_before_returning_equipment_to_operational(): void
    {
        $equipment = Equipment::factory()->status('out_of_service')->create();
        $service = app(MaintenanceService::class);
        $first = $service->reportBreakdown($this->technician, $equipment, ['type' => 'corrective', 'priority' => 'urgent', 'description' => 'Pump seized']);

        try {
            $service->complete($this->technician, $first, ['downtime_minutes' => 120]);
            $this->fail('The question must be answered.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('return_to_operational', $exception->errors());
        }

        $service->complete($this->technician, $first, ['downtime_minutes' => 120, 'return_to_operational' => false]);
        $this->assertSame('out_of_service', $equipment->refresh()->status, 'Answering no leaves the status alone.');

        $second = $service->reportBreakdown($this->technician, $equipment, ['type' => 'corrective', 'priority' => 'urgent', 'description' => 'Second attempt']);
        $this->actingAs($this->technician);
        Livewire::test(WorkOrderDetail::class, ['workOrder' => $second])
            ->call('openComplete')
            ->assertSee('Return it to operational?')
            ->set('downtimeMinutes', '30')
            ->set('returnToOperational', '1')
            ->call('complete')
            ->assertHasNoErrors();

        $this->assertSame('operational', $equipment->refresh()->status);
    }

    public function test_scheduling_on_a_line_with_equipment_down_shows_a_warning_and_the_line_status(): void
    {
        $filler = Equipment::factory()->create(['code' => 'FL-01', 'name' => 'Rotary filler', 'production_line_id' => $this->line->id]);
        $healthyLine = ProductionLine::query()->create(['name' => 'Line 02', 'status' => 'active']);
        Equipment::factory()->create(['production_line_id' => $healthyLine->id]);
        app(MaintenanceService::class)->setEquipmentStatus($this->technician, $filler, 'out_of_service', 'Motor burnt out');
        $this->actingAs($this->productionManager);

        Livewire::test(CreateScheduleModal::class)
            ->call('openModal')
            ->set('productionLineId', $healthyLine->id)
            ->assertDontSee('has equipment that is')
            ->set('productionLineId', $this->line->id)
            ->assertSee('Line 01 has equipment that is out of service: FL-01 Rotary filler (out of service).');

        $this->get(route('production.schedule'))
            ->assertOk()
            ->assertSee('Line status')
            ->assertSee('FL-01 Rotary filler');

        $statuses = app(LineStatus::class)->all()->keyBy(fn (array $summary): int => $summary['line']->id);
        $this->assertSame('out_of_service', $statuses[$this->line->id]['status']);
        $this->assertSame('operational', $statuses[$healthyLine->id]['status']);
        $this->assertNull(app(LineStatus::class)->warningFor($healthyLine->id));
    }

    public function test_out_of_service_notifies_production_roles_and_assignment_notifies_the_assignee(): void
    {
        $equipment = Equipment::factory()->create(['production_line_id' => $this->line->id]);
        $operator = $this->userWithRole('production-staff');
        $clerk = $this->userWithRole('inventory-staff');
        $service = app(MaintenanceService::class);

        $service->setEquipmentStatus($this->technician, $equipment, 'out_of_service');

        $this->assertSame('maintenance.equipment_out_of_service', $operator->notifications()->sole()->data['type']);
        $this->assertSame(1, $this->productionManager->notifications()->count());
        $this->assertSame(0, $clerk->notifications()->count());

        $colleague = $this->userWithRole('maintenance-staff');
        $workOrder = $service->reportBreakdown($this->technician, $equipment, ['type' => 'corrective', 'priority' => 'normal', 'description' => 'Check the motor']);
        $service->assign($this->technician, $workOrder, $colleague->id);

        $this->assertSame('maintenance.work_order_assigned', $colleague->notifications()->sole()->data['type']);
    }

    public function test_a_production_request_about_equipment_becomes_a_work_order_in_one_click(): void
    {
        $equipment = Equipment::factory()->create(['code' => 'CP-02', 'name' => 'Capper', 'production_line_id' => $this->line->id]);
        $request = app(RequestService::class)->create($this->productionManager, [
            'type' => 'general',
            'subject' => 'Capper is misaligned',
            'body' => 'Caps are going on crooked since 10:00.',
            'priority' => 'urgent',
            'to_role_id' => $this->technician->role_id,
            'related_type' => 'equipment',
            'related_id' => $equipment->id,
        ]);
        $this->assertSame('Equipment CP-02 · Capper', $request->context_snapshot['label']);

        $this->actingAs($this->productionManager);
        Livewire::test(RequestDetail::class, ['departmentRequest' => $request])->assertDontSee('Create work order');

        $this->actingAs($this->technician);
        Livewire::test(RequestDetail::class, ['departmentRequest' => $request])
            ->assertSee('Create work order')
            ->call('createWorkOrder')
            ->assertRedirect(route('maintenance.work-orders.show', WorkOrder::query()->sole()));

        $workOrder = WorkOrder::query()->sole();
        $this->assertSame(['corrective', 'request', 'urgent', $request->id, $equipment->id, $this->technician->id],
            [$workOrder->type, $workOrder->source, $workOrder->priority, $workOrder->request_id, $workOrder->equipment_id, $workOrder->assigned_to]);
        $this->assertSame(RequestStatus::InProgress, $request->refresh()->status);
        $this->assertStringContainsString($workOrder->reference, $request->comments()->sole()->body);

        app(MaintenanceService::class)->createFromRequest($this->technician, $request);
        $this->assertSame(1, WorkOrder::query()->count(), 'Clicking again reuses the work order.');
    }

    public function test_downtime_is_summed_per_equipment_and_per_line_over_30_and_90_days(): void
    {
        $filler = Equipment::factory()->create(['production_line_id' => $this->line->id]);
        $capper = Equipment::factory()->create(['production_line_id' => $this->line->id]);
        $tank = Equipment::factory()->create();
        WorkOrder::factory()->for($filler)->done(30, now()->subDays(5)->toDateTimeString())->create();
        WorkOrder::factory()->for($filler)->done(60, now()->subDays(40)->toDateTimeString())->create();
        WorkOrder::factory()->for($capper)->done(15, now()->subDays(2)->toDateTimeString())->create();
        WorkOrder::factory()->for($tank)->done(100, now()->subDays(1)->toDateTimeString())->create();
        WorkOrder::factory()->for($filler)->create(['description' => 'Still open']);

        $this->assertSame(30, $filler->downtimeMinutesSince(now()->subDays(30)));
        $this->assertSame(90, $filler->downtimeMinutesSince(now()->subDays(90)));

        $service = app(MaintenanceService::class);
        $this->assertSame([$this->line->id => 45], $service->downtimeByLine(30));
        $this->assertSame([$this->line->id => 105], $service->downtimeByLine(90));
        $this->assertSame(100, $service->downtimeByEquipment(30)[$tank->id]);

        $this->actingAs($this->technician)
            ->get(route('maintenance.equipment.show', $filler))
            ->assertOk()
            ->assertSeeInOrder(['Downtime, last 30 days', '30 min', 'Downtime, last 90 days', '90 min']);
    }

    public function test_dashboard_shows_maintenance_widgets_and_attention_items_only_with_access(): void
    {
        $equipment = Equipment::factory()->status('out_of_service')->create(['code' => 'RO-01', 'name' => 'RO unit']);
        MaintenancePlan::factory()->create(['equipment_id' => $equipment->id, 'task' => 'Replace RO membrane', 'first_due_on' => '2026-10-20']);

        $this->actingAs($this->technician);
        $component = Livewire::test(Dashboard::class)
            ->assertSee('Maintenance due')
            ->assertSee('Open work orders')
            ->assertSee('Equipment not operational')
            ->assertSee('RO-01 RO unit is out of service')
            ->assertSee('Overdue maintenance: Replace RO membrane');
        $this->assertSame(['critical', 'high'], array_column($component->viewData('attentionItems'), 'severity'));

        $this->actingAs($this->userWithRole('inventory-staff'));
        Livewire::test(Dashboard::class)
            ->assertDontSee('Maintenance due')
            ->assertDontSee('RO-01 RO unit is out of service');
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
