<?php

namespace App\Livewire\Maintenance;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Maintenance\MaintenanceService;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Work Order', 'breadcrumbSection' => 'Maintenance', 'activeItem' => 'Work Orders'])]
class WorkOrderDetail extends Component
{
    #[Locked]
    public int $workOrderId;

    public bool $completing = false;

    public bool $cancelling = false;

    public string $downtimeMinutes = '0';

    public string $partsUsed = '';

    public string $costKes = '';

    public string $completionNotes = '';

    /**
     * Ticked checklist steps, keyed by their position in the plan's checklist.
     *
     * @var array<int, bool>
     */
    public array $checklistResults = [];

    /**
     * Null until the person completing answers; the equipment status is never changed for them.
     */
    public ?string $returnToOperational = null;

    public string $cancelReason = '';

    public string $assignTo = '';

    public function mount(WorkOrder $workOrder): void
    {
        $this->workOrderId = $workOrder->id;
        $this->assignTo = (string) $workOrder->assigned_to;
    }

    public function start(MaintenanceService $maintenance): void
    {
        $maintenance->start($this->authorizeWrite(), $this->workOrder());
    }

    public function assign(MaintenanceService $maintenance): void
    {
        $maintenance->assign($this->authorizeWrite(), $this->workOrder(), $this->assignTo === '' ? null : (int) $this->assignTo);
    }

    public function openComplete(): void
    {
        $this->authorizeWrite();
        $this->resetValidation();
        $steps = $this->workOrder()->plan?->checklistSteps() ?? [];
        $this->checklistResults = array_fill(0, count($steps), false);
        $this->completing = true;
    }

    public function complete(MaintenanceService $maintenance): void
    {
        $maintenance->complete($this->authorizeWrite(), $this->workOrder(), [
            'downtime_minutes' => $this->downtimeMinutes,
            'parts_used' => $this->partsUsed,
            'cost_kes' => $this->costKes === '' ? null : $this->costKes,
            'checklist_results' => $this->checklistResults,
            'completion_notes' => $this->completionNotes,
            'return_to_operational' => $this->returnToOperational === null ? null : $this->returnToOperational === '1',
        ]);

        $this->completing = false;
    }

    public function closeComplete(): void
    {
        $this->resetValidation();
        $this->completing = false;
    }

    public function closeCancel(): void
    {
        $this->resetValidation();
        $this->cancelling = false;
    }

    public function cancelWorkOrder(MaintenanceService $maintenance): void
    {
        $maintenance->cancel($this->authorizeWrite(), $this->workOrder(), $this->cancelReason);
        $this->cancelling = false;
    }

    public function render(MaintenanceService $maintenance): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'maintenance');

        $workOrder = $this->workOrder()->load(['equipment.productionLine:id,name', 'plan', 'request:id,reference,subject', 'reporter:id,name', 'assignee:id,name']);

        return view('livewire.maintenance.work-order-detail', [
            'workOrder' => $workOrder,
            'steps' => $workOrder->plan?->checklistSteps() ?? [],
            'assignees' => $maintenance->assignableUsers(),
            'canManage' => app(ModuleAccess::class)->can($user, 'maintenance', write: true),
        ])->title($workOrder->reference.' · Work Orders');
    }

    private function workOrder(): WorkOrder
    {
        return WorkOrder::query()->findOrFail($this->workOrderId);
    }

    private function authorizeWrite(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'maintenance', write: true);

        return $user;
    }
}
