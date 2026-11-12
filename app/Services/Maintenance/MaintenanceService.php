<?php

namespace App\Services\Maintenance;

use App\Models\AuditLog;
use App\Models\DepartmentRequest;
use App\Models\Equipment;
use App\Models\MaintenancePlan;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\NotificationService;
use App\Services\Requests\RequestAccess;
use App\Services\Requests\RequestService;
use App\Services\Requests\RequestStatus;
use App\Services\SettingsService;
use App\Support\ModuleAccess;
use App\Support\YearlySequence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Every change to equipment, maintenance plans and work orders. Writes need full access to the
 * maintenance module, are audited, and announce themselves through NotificationService.
 */
class MaintenanceService
{
    /**
     * Plans due within this many days get a work order from the daily generator.
     */
    public const GENERATE_AHEAD_DAYS = 2;

    public function __construct(
        private ModuleAccess $access,
        private YearlySequence $sequence,
        private NotificationService $notifications,
        private SettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function saveEquipment(User $actor, ?Equipment $equipment, array $input): Equipment
    {
        $this->authorizeWrite($actor);

        $validated = Validator::make($input, [
            'code' => ['required', 'string', 'max:30', Rule::unique('equipment', 'code')->ignore($equipment?->id)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(Equipment::TYPES)],
            'production_line_id' => ['nullable', 'integer', Rule::exists('production_lines', 'id')],
            'location' => ['nullable', 'string', 'max:120'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'installed_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $equipment, $validated): Equipment {
            $record = $equipment ?? new Equipment;
            $old = $record->exists ? $record->only(array_keys($validated)) : null;
            $record->fill($validated);
            $record->status ??= 'operational';
            $record->save();
            $this->audit($actor, $old === null ? 'maintenance.equipment_created' : 'maintenance.equipment_updated', 'equipment', $old, $record->only([...array_keys($validated), 'status']));

            return $record;
        });
    }

    /**
     * @throws ValidationException
     */
    public function setEquipmentStatus(User $actor, Equipment $equipment, string $status, ?string $note = null): Equipment
    {
        $this->authorizeWrite($actor);

        if (! in_array($status, Equipment::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Choose a valid equipment status.']);
        }

        return $this->changeStatus($actor, $equipment, $status, $note);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function savePlan(User $actor, Equipment $equipment, ?MaintenancePlan $plan, array $input): MaintenancePlan
    {
        $this->authorizeWrite($actor);

        $input['checklist'] = array_values(array_filter(
            array_map(fn (mixed $step): string => trim((string) $step), is_array($input['checklist'] ?? null) ? $input['checklist'] : preg_split('/\R/', (string) ($input['checklist'] ?? ''))),
            fn (string $step): bool => $step !== '',
        ));

        $validated = Validator::make($input, [
            'task' => ['required', 'string', 'max:160'],
            'every_days' => ['required', 'integer', 'between:1,3650'],
            'first_due_on' => ['required', 'date_format:Y-m-d'],
            'last_done_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'assigned_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'checklist' => ['array', 'max:30'],
            'checklist.*' => ['string', 'max:200'],
            'active' => ['required', 'boolean'],
        ], [], ['every_days' => 'interval'])->validate();

        return DB::transaction(function () use ($actor, $equipment, $plan, $validated): MaintenancePlan {
            $record = $plan ?? new MaintenancePlan(['equipment_id' => $equipment->id]);
            $old = $record->exists ? $record->only(['task', 'every_days', 'first_due_on', 'last_done_on', 'next_due_on', 'active']) : null;
            $record->fill([...$validated, 'active' => filter_var($validated['active'], FILTER_VALIDATE_BOOLEAN)])->save();
            $this->audit($actor, $old === null ? 'maintenance.plan_created' : 'maintenance.plan_updated', 'maintenance_plans', $old, $record->only(['task', 'every_days', 'first_due_on', 'last_done_on', 'next_due_on', 'active']));

            return $record;
        });
    }

    /**
     * A corrective work order for a breakdown someone has seen. Optionally marks the equipment down too.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function reportBreakdown(User $actor, Equipment $equipment, array $input): WorkOrder
    {
        $this->authorizeWrite($actor);

        $validated = Validator::make($input, [
            'type' => ['required', Rule::in(WorkOrder::TYPES)],
            'priority' => ['required', Rule::in(WorkOrder::PRIORITIES)],
            'description' => ['required', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'equipment_status' => ['nullable', Rule::in(Equipment::STATUSES)],
        ])->validate();

        return DB::transaction(function () use ($actor, $equipment, $validated): WorkOrder {
            $workOrder = $this->createWorkOrder($actor, [
                'equipment_id' => $equipment->id,
                'type' => $validated['type'],
                'source' => 'breakdown',
                'priority' => $validated['priority'],
                'description' => trim($validated['description']),
                'assigned_to' => $validated['assigned_to'] ?? null,
                'reported_by' => $actor->id,
            ]);

            if (($validated['equipment_status'] ?? null) !== null && $validated['equipment_status'] !== $equipment->status) {
                $this->changeStatus($actor, $equipment, $validated['equipment_status'], "Reported with {$workOrder->reference}.");
            }

            return $workOrder;
        });
    }

    /**
     * Turns a request about a piece of equipment into a corrective work order. Running it twice returns
     * the work order already created, so the button is safe to click more than once.
     */
    public function createFromRequest(User $actor, DepartmentRequest $request): WorkOrder
    {
        $this->authorizeWrite($actor);
        abort_unless(app(RequestAccess::class)->canManage($actor, $request), 403);
        abort_unless($request->related_type === 'equipment' && $request->related_id !== null, 422, 'This request is not about a piece of equipment.');

        $existing = WorkOrder::query()->where('request_id', $request->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $equipment = Equipment::query()->findOrFail($request->related_id);

        $workOrder = DB::transaction(fn (): WorkOrder => $this->createWorkOrder($actor, [
            'equipment_id' => $equipment->id,
            'request_id' => $request->id,
            'type' => 'corrective',
            'source' => 'request',
            'priority' => $request->priority->value,
            'description' => "{$request->subject}\n\n{$request->body}",
            'reported_by' => $request->from_user_id,
            'assigned_to' => $actor->id,
        ]));

        $requests = app(RequestService::class);
        $requests->comment($actor, $request, __('maintenance.request_created', ['reference' => $workOrder->reference]));

        if (in_array(RequestStatus::InProgress, $request->refresh()->status->recipientTransitions(), true)) {
            $requests->changeStatus($actor, $request, RequestStatus::InProgress);
        }

        return $workOrder;
    }

    public function assign(User $actor, WorkOrder $workOrder, ?int $userId): WorkOrder
    {
        $this->authorizeWrite($actor);

        // Only people who can actually do the work: a suspended user or one without maintenance access can't.
        if ($userId !== null && ! $this->assignableUsers()->contains('id', $userId)) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose an active user with full maintenance access.']);
        }

        [$workOrder, $previous] = DB::transaction(function () use ($workOrder, $userId): array {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            abort_unless($locked->isActive(), 409, 'Only open work orders can be reassigned.');

            $previous = $locked->assigned_to;
            $locked->assigned_to = $userId;
            $locked->save();

            return [$locked, $previous];
        });
        $this->audit($actor, 'maintenance.work_order_assigned', 'work_orders', ['reference' => $workOrder->reference, 'assigned_to' => $previous], ['reference' => $workOrder->reference, 'assigned_to' => $userId]);

        if ($userId !== null && $userId !== $previous) {
            $this->notifications->workOrderAssigned($workOrder, $actor);
        }

        return $workOrder;
    }

    public function start(User $actor, WorkOrder $workOrder): WorkOrder
    {
        $this->authorizeWrite($actor);

        return DB::transaction(function () use ($actor, $workOrder): WorkOrder {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            abort_unless($locked->status === 'open', 409, 'Only open work orders can be started.');

            $locked->status = 'in_progress';
            $locked->started_at = now();
            $locked->assigned_to ??= $actor->id;
            $locked->save();
            $this->audit($actor, 'maintenance.work_order_started', 'work_orders', ['reference' => $locked->reference, 'status' => 'open'], ['reference' => $locked->reference, 'status' => 'in_progress']);

            return $locked;
        });
    }

    /**
     * Completes a work order. A preventive one moves its plan's last and next due dates. When the
     * equipment isn't operational, the person completing must say whether it is working again; the
     * status is never changed on their behalf.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function complete(User $actor, WorkOrder $workOrder, array $input): WorkOrder
    {
        $this->authorizeWrite($actor);
        $equipment = $workOrder->equipment()->firstOrFail();

        $validated = Validator::make($input, [
            'downtime_minutes' => ['required', 'integer', 'between:0,100000'],
            'parts_used' => ['nullable', 'string', 'max:2000'],
            'cost_kes' => ['nullable', 'numeric', 'between:0,99999999.99'],
            'checklist_results' => ['array'],
            'checklist_results.*' => ['boolean'],
            'completion_notes' => ['nullable', 'string', 'max:5000'],
            'return_to_operational' => [$equipment->isOperational() ? 'nullable' : 'required', 'boolean'],
        ], [
            'return_to_operational.required' => 'Say whether the equipment is working again.',
        ])->validate();

        return DB::transaction(function () use ($actor, $workOrder, $equipment, $validated): WorkOrder {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            abort_unless($locked->isActive(), 409, 'This work order is already closed.');

            $completedAt = now();
            $locked->status = 'done';
            $locked->started_at ??= $completedAt;
            $locked->completed_at = $completedAt;
            $locked->downtime_minutes = (int) $validated['downtime_minutes'];
            $locked->parts_used = filled($validated['parts_used'] ?? null) ? trim($validated['parts_used']) : null;
            $locked->cost_kes = $validated['cost_kes'] ?? null;
            $locked->checklist_results = array_map(fn (mixed $done): bool => filter_var($done, FILTER_VALIDATE_BOOLEAN), $validated['checklist_results'] ?? []);
            $locked->completion_notes = filled($validated['completion_notes'] ?? null) ? trim($validated['completion_notes']) : null;
            $locked->assigned_to ??= $actor->id;
            $locked->save();

            $this->audit($actor, 'maintenance.work_order_completed', 'work_orders', ['reference' => $locked->reference, 'status' => $workOrder->status], [
                'reference' => $locked->reference,
                'status' => 'done',
                'downtime_minutes' => $locked->downtime_minutes,
                'cost_kes' => $locked->cost_kes,
            ]);

            if ($locked->type === 'preventive' && $locked->maintenance_plan_id !== null) {
                $plan = MaintenancePlan::query()->lockForUpdate()->find($locked->maintenance_plan_id);

                if ($plan !== null) {
                    $plan->last_done_on = $this->operationsToday()->toDateString();
                    $plan->save();
                }
            }

            if (filter_var($validated['return_to_operational'] ?? false, FILTER_VALIDATE_BOOLEAN) && ! $equipment->isOperational()) {
                $this->changeStatus($actor, $equipment, 'operational', "Returned to operational when {$locked->reference} was completed.");
            }

            return $locked;
        });
    }

    public function cancel(User $actor, WorkOrder $workOrder, string $reason): WorkOrder
    {
        $this->authorizeWrite($actor);

        $validated = Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500']])->validate();

        return DB::transaction(function () use ($actor, $workOrder, $validated): WorkOrder {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            abort_unless($locked->isActive(), 409, 'This work order is already closed.');

            $locked->status = 'cancelled';
            $locked->completion_notes = trim($validated['reason']);
            $locked->save();
            $this->audit($actor, 'maintenance.work_order_cancelled', 'work_orders', ['reference' => $locked->reference, 'status' => $workOrder->status], ['reference' => $locked->reference, 'status' => 'cancelled', 'reason' => $locked->completion_notes]);

            return $locked;
        });
    }

    /**
     * Creates a preventive work order for each active plan due within the next two days that has no open
     * work order, and reminds the plan's role about plans already overdue. Safe to run any number of times:
     * a plan never has two open work orders. A cancelled work order doesn't count, so the task comes back
     * on the next run while its plan is still active; deactivate the plan to stop it.
     *
     * @return array{created: list<string>, overdue: int}
     */
    public function generateDueWorkOrders(?CarbonImmutable $today = null): array
    {
        $today ??= $this->operationsToday();
        $plans = MaintenancePlan::query()
            ->with('equipment:id,code,name')
            ->where('active', true)
            ->whereDate('next_due_on', '<=', $today->addDays(self::GENERATE_AHEAD_DAYS)->toDateString())
            ->orderBy('next_due_on')
            ->orderBy('id')
            ->get();
        $created = [];
        $overdue = 0;

        foreach ($plans as $plan) {
            // One plan that can't be processed must not stop the rest of the run.
            try {
                $reference = DB::transaction(function () use ($plan, $today): ?string {
                    // Serialises overlapping runs, so a plan can't get two open work orders.
                    $locked = MaintenancePlan::query()->lockForUpdate()->findOrFail($plan->id);
                    $hasOpenWorkOrder = WorkOrder::query()
                        ->where('maintenance_plan_id', $locked->id)
                        ->whereIn('status', WorkOrder::ACTIVE_STATUSES)
                        ->exists();

                    if (! $locked->active || $hasOpenWorkOrder) {
                        return null;
                    }

                    $dueOn = $locked->next_due_on->toDateString();

                    return $this->createWorkOrder(null, [
                        'equipment_id' => $locked->equipment_id,
                        'maintenance_plan_id' => $locked->id,
                        'due_on' => $dueOn,
                        'type' => 'preventive',
                        'source' => 'plan',
                        'priority' => $locked->next_due_on->lessThan($today) ? 'urgent' : 'normal',
                        'description' => $locked->task.' ('.$plan->equipment?->code.'), due '.$dueOn,
                    ])->reference;
                });

                if ($reference !== null) {
                    $created[] = $reference;
                }

                if ($plan->next_due_on->lessThan($today)) {
                    $overdue++;
                    $this->notifications->maintenancePlanOverdue($plan);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return ['created' => $created, 'overdue' => $overdue];
    }

    /**
     * People a work order can be assigned to: everyone active with full maintenance access.
     *
     * @return Collection<int, User>
     */
    public function assignableUsers(): Collection
    {
        return $this->notifications->usersWithModuleAccess(['maintenance'], ['full'])->sortBy('name')->values();
    }

    /**
     * Downtime minutes from work orders completed in the last N days, per production line.
     *
     * @return array<int, int>
     */
    public function downtimeByLine(int $days): array
    {
        return DB::table('work_orders')
            ->join('equipment', 'equipment.id', '=', 'work_orders.equipment_id')
            ->whereNotNull('equipment.production_line_id')
            ->where('work_orders.status', 'done')
            ->where('work_orders.completed_at', '>=', now()->subDays($days))
            ->groupBy('equipment.production_line_id')
            ->selectRaw('equipment.production_line_id as line_id, COALESCE(SUM(work_orders.downtime_minutes), 0) as minutes')
            ->pluck('minutes', 'line_id')
            ->map(fn (mixed $minutes): int => (int) $minutes)
            ->all();
    }

    /**
     * Downtime minutes from work orders completed in the last N days, per piece of equipment.
     *
     * @return array<int, int>
     */
    public function downtimeByEquipment(int $days): array
    {
        return DB::table('work_orders')
            ->where('status', 'done')
            ->where('completed_at', '>=', now()->subDays($days))
            ->groupBy('equipment_id')
            ->selectRaw('equipment_id, COALESCE(SUM(downtime_minutes), 0) as minutes')
            ->pluck('minutes', 'equipment_id')
            ->map(fn (mixed $minutes): int => (int) $minutes)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createWorkOrder(?User $actor, array $attributes): WorkOrder
    {
        $workOrder = new WorkOrder([
            ...$attributes,
            'reference' => $this->sequence->next('work_order_number_sequences', 'WO', (int) now()->format('Y')),
        ]);
        $workOrder->status = 'open';
        $workOrder->save();

        $this->audit($actor, 'maintenance.work_order_created', 'work_orders', null, [
            'reference' => $workOrder->reference,
            'equipment_id' => $workOrder->equipment_id,
            'type' => $workOrder->type,
            'source' => $workOrder->source,
        ]);

        if ($workOrder->assigned_to !== null && $workOrder->assigned_to !== $actor?->id) {
            $this->notifications->workOrderAssigned($workOrder, $actor);
        }

        return $workOrder;
    }

    private function changeStatus(User $actor, Equipment $equipment, string $status, ?string $note): Equipment
    {
        $previous = $equipment->status;

        if ($previous === $status) {
            return $equipment;
        }

        $equipment->status = $status;
        $equipment->save();
        $this->audit($actor, 'maintenance.equipment_status_changed', 'equipment', ['code' => $equipment->code, 'status' => $previous], ['code' => $equipment->code, 'status' => $status, 'note' => $note]);

        if ($status === 'out_of_service') {
            $this->notifications->equipmentOutOfService($equipment, $actor);
        }

        return $equipment;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(?User $actor, string $action, string $table, ?array $old, ?array $new): void
    {
        AuditLog::query()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'table_name' => $table,
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }

    private function authorizeWrite(User $actor): void
    {
        $this->access->authorize($actor, 'maintenance', write: true);
    }

    private function operationsToday(): CarbonImmutable
    {
        return CarbonImmutable::now((string) $this->settings->get('organisation.timezone', config('app.timezone')))->startOfDay();
    }
}
