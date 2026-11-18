<?php

namespace App\Livewire\Maintenance;

use App\Models\Equipment;
use App\Models\ProductionLine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Work Orders', 'breadcrumbSection' => 'Maintenance', 'activeItem' => 'Work Orders'])]
class WorkOrdersBoard extends Component
{
    /**
     * The Done column shows work finished in this many days.
     */
    public const DONE_DAYS = 30;

    private const COLUMN_LIMIT = 50;

    #[Url(as: 'equipment', except: '')]
    public string $equipmentFilter = '';

    #[Url(as: 'line', except: '')]
    public string $lineFilter = '';

    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

    #[Url(as: 'priority', except: '')]
    public string $priorityFilter = '';

    #[Url(as: 'mine', except: false)]
    public bool $mineOnly = false;

    public function clearFilters(): void
    {
        $this->reset(['equipmentFilter', 'lineFilter', 'typeFilter', 'priorityFilter', 'mineOnly']);
    }

    public function render(): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'maintenance');

        $columns = [];

        foreach (['open', 'in_progress', 'done'] as $status) {
            $columns[$status] = $this->filtered($user)
                ->with(['equipment:id,code,name,production_line_id', 'assignee:id,name'])
                ->where('status', $status)
                ->when($status === 'done', fn (Builder $query) => $query->where('completed_at', '>=', now()->subDays(self::DONE_DAYS)))
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")
                ->orderBy($status === 'done' ? 'completed_at' : 'created_at', $status === 'done' ? 'desc' : 'asc')
                ->limit(self::COLUMN_LIMIT)
                ->get();
        }

        return view('livewire.maintenance.work-orders-board', [
            'columns' => $columns,
            'equipment' => Equipment::query()->orderBy('code')->get(['id', 'code', 'name']),
            'lines' => ProductionLine::query()->orderBy('name')->get(['id', 'name']),
            'hasFilters' => $this->equipmentFilter !== '' || $this->lineFilter !== '' || $this->typeFilter !== '' || $this->priorityFilter !== '' || $this->mineOnly,
        ]);
    }

    /**
     * @return Builder<WorkOrder>
     */
    private function filtered(User $user): Builder
    {
        return WorkOrder::query()
            ->when(ctype_digit($this->equipmentFilter), fn (Builder $query) => $query->where('equipment_id', (int) $this->equipmentFilter))
            ->when(ctype_digit($this->lineFilter), fn (Builder $query) => $query->whereHas('equipment', fn (Builder $query) => $query->where('production_line_id', (int) $this->lineFilter)))
            ->when(in_array($this->typeFilter, WorkOrder::TYPES, true), fn (Builder $query) => $query->where('type', $this->typeFilter))
            ->when(in_array($this->priorityFilter, WorkOrder::PRIORITIES, true), fn (Builder $query) => $query->where('priority', $this->priorityFilter))
            ->when($this->mineOnly, fn (Builder $query) => $query->where('assigned_to', $user->id));
    }
}
