<?php

namespace App\Livewire\Maintenance;

use App\Models\Equipment;
use App\Models\Role;
use App\Models\User;
use App\Services\Maintenance\MaintenanceService;
use App\Services\Requests\RequestAccess;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Equipment', 'breadcrumbSection' => 'Maintenance', 'activeItem' => 'Equipment'])]
class EquipmentDetail extends Component
{
    private const HISTORY_LIMIT = 25;

    #[Locked]
    public int $equipmentId;

    public ?string $openForm = null;

    #[Locked]
    public ?int $editingPlanId = null;

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    public function mount(Equipment $equipment): void
    {
        $this->equipmentId = $equipment->id;
    }

    public function openStatus(): void
    {
        $this->authorizeWrite();
        $this->resetForm();
        $this->openForm = 'status';
        $this->form = ['status' => $this->equipment()->status, 'note' => ''];
    }

    public function openPlan(?int $planId = null): void
    {
        $this->authorizeWrite();
        $this->resetForm();
        $plan = $planId === null ? null : $this->equipment()->plans()->findOrFail($planId);
        $this->openForm = 'plan';
        $this->editingPlanId = $plan?->id;
        $this->form = [
            'task' => $plan?->task ?? '',
            'every_days' => (string) ($plan?->every_days ?? 30),
            'first_due_on' => $plan?->first_due_on->toDateString() ?? today()->toDateString(),
            'last_done_on' => $plan?->last_done_on?->toDateString() ?? '',
            'assigned_role_id' => (string) ($plan?->assigned_role_id ?? Role::query()->where('slug', 'maintenance-staff')->value('id')),
            'checklist' => implode("\n", $plan?->checklistSteps() ?? []),
            'active' => $plan?->active ?? true,
        ];
    }

    public function openBreakdown(): void
    {
        $this->authorizeWrite();
        $this->resetForm();
        $this->openForm = 'breakdown';
        $this->form = ['type' => 'corrective', 'priority' => 'urgent', 'description' => '', 'assigned_to' => '', 'equipment_status' => ''];
    }

    public function submit(MaintenanceService $maintenance)
    {
        $user = $this->authorizeWrite();
        $equipment = $this->equipment();
        $input = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $this->form);

        try {
            $result = match ($this->openForm) {
                'status' => $maintenance->setEquipmentStatus($user, $equipment, (string) $input['status'], $input['note'] ?? null),
                'plan' => $maintenance->savePlan($user, $equipment, $this->editingPlanId === null ? null : $equipment->plans()->findOrFail($this->editingPlanId), $input),
                'breakdown' => $maintenance->reportBreakdown($user, $equipment, $input),
                default => abort(404),
            };
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ['form.'.explode('.', $field)[0] => $messages])->all());
        }

        if ($this->openForm === 'breakdown') {
            return $this->redirectRoute('maintenance.work-orders.show', $result);
        }

        $this->resetForm();

        return null;
    }

    public function closeForm(): void
    {
        $this->resetForm();
    }

    public function render(MaintenanceService $maintenance): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'maintenance');

        $equipment = $this->equipment()->load(['productionLine:id,name', 'plans.assignedRole:id,name']);

        return view('livewire.maintenance.equipment-detail', [
            'equipment' => $equipment,
            'plans' => $equipment->plans->sortBy([['active', 'desc'], ['next_due_on', 'asc']])->values(),
            'workOrders' => $equipment->workOrders()->with('assignee:id,name')->latest()->limit(self::HISTORY_LIMIT)->get(),
            'downtime30' => $equipment->downtimeMinutesSince(now()->subDays(30)),
            'downtime90' => $equipment->downtimeMinutesSince(now()->subDays(90)),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'assignees' => $maintenance->assignableUsers(),
            'canManage' => app(ModuleAccess::class)->can($user, 'maintenance', write: true),
            'canSendRequests' => app(RequestAccess::class)->canSendRequests($user),
        ])->title($equipment->code.' · '.$equipment->name);
    }

    private function equipment(): Equipment
    {
        return Equipment::query()->findOrFail($this->equipmentId);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['openForm', 'editingPlanId', 'form']);
    }

    private function authorizeWrite(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'maintenance', write: true);

        return $user;
    }
}
