<?php

namespace App\Livewire\Maintenance;

use App\Models\Equipment;
use App\Models\MaintenancePlan;
use App\Models\ProductionLine;
use App\Models\User;
use App\Services\Maintenance\MaintenanceService;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Equipment', 'breadcrumbSection' => 'Maintenance', 'activeItem' => 'Equipment'])]
class EquipmentList extends Component
{
    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'line', except: '')]
    public string $lineFilter = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    public bool $formOpen = false;

    #[Locked]
    public ?int $editingId = null;

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    public function openCreate(): void
    {
        $this->authorizeWrite();
        $this->resetValidation();
        $this->editingId = null;
        $this->form = ['code' => '', 'name' => '', 'type' => 'filler', 'production_line_id' => '', 'location' => '', 'manufacturer' => '', 'model' => '', 'serial_number' => '', 'installed_on' => '', 'notes' => ''];
        $this->formOpen = true;
    }

    public function openEdit(int $equipmentId): void
    {
        $this->authorizeWrite();
        $equipment = Equipment::query()->findOrFail($equipmentId);
        $this->resetValidation();
        $this->editingId = $equipment->id;
        $this->form = [
            ...collect($equipment->only(['code', 'name', 'type', 'location', 'manufacturer', 'model', 'serial_number', 'notes']))->map(fn (mixed $value): string => (string) $value)->all(),
            'production_line_id' => (string) $equipment->production_line_id,
            'installed_on' => $equipment->installed_on?->toDateString() ?? '',
        ];
        $this->formOpen = true;
    }

    public function save(MaintenanceService $maintenance): void
    {
        $user = $this->authorizeWrite();
        $equipment = $this->editingId === null ? null : Equipment::query()->findOrFail($this->editingId);

        try {
            $maintenance->saveEquipment($user, $equipment, array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $this->form));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ["form.{$field}" => $messages])->all());
        }

        $this->closeForm();
    }

    public function closeForm(): void
    {
        $this->resetValidation();
        $this->reset(['formOpen', 'editingId', 'form']);
    }

    public function render(MaintenanceService $maintenance): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'maintenance');

        $equipment = Equipment::query()
            ->with('productionLine:id,name')
            ->addSelect([
                'next_task' => MaintenancePlan::query()->select('task')->whereColumn('equipment_id', 'equipment.id')->where('active', true)->orderBy('next_due_on')->limit(1),
                'next_due_on' => MaintenancePlan::query()->select('next_due_on')->whereColumn('equipment_id', 'equipment.id')->where('active', true)->orderBy('next_due_on')->limit(1),
            ])
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('code', 'like', '%'.trim($this->search).'%')
                ->orWhere('name', 'like', '%'.trim($this->search).'%')))
            ->when($this->lineFilter === 'none', fn (Builder $query) => $query->whereNull('production_line_id'))
            ->when(ctype_digit($this->lineFilter), fn (Builder $query) => $query->where('production_line_id', (int) $this->lineFilter))
            ->when(in_array($this->statusFilter, Equipment::STATUSES, true), fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->orderBy('code')
            ->get();

        return view('livewire.maintenance.equipment-list', [
            'equipment' => $equipment,
            'lines' => ProductionLine::query()->orderBy('name')->get(['id', 'name']),
            'downtime' => $maintenance->downtimeByEquipment(30),
            'canManage' => app(ModuleAccess::class)->can($user, 'maintenance', write: true),
            'hasFilters' => $this->search !== '' || $this->lineFilter !== '' || $this->statusFilter !== '',
        ]);
    }

    private function authorizeWrite(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'maintenance', write: true);

        return $user;
    }
}
