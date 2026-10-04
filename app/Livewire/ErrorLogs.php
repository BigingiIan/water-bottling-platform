<?php

namespace App\Livewire;

use App\Models\ErrorEvent;
use App\Models\Module;
use App\Models\User;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Error Logs', 'activeItem' => 'Error Logs'])]
class ErrorLogs extends Component
{
    use WithPagination;

    public ?string $statusFilter = null;

    public ?string $userFilter = null;

    public ?string $moduleFilter = null;

    public ?string $startDate = null;

    public ?string $endDate = null;

    public string $referenceSearch = '';

    #[Locked]
    public ?int $selectedEventId = null;

    public function updatedStatusFilter(): void
    {
        $this->validateOnly('statusFilter');
        $this->resetPage();
    }

    public function updatedUserFilter(): void
    {
        $this->validateOnly('userFilter');
        $this->resetPage();
    }

    public function updatedModuleFilter(): void
    {
        $this->validateOnly('moduleFilter');
        $this->resetPage();
    }

    public function updatedStartDate(): void
    {
        $this->validateOnly('startDate');
        $this->resetPage();
    }

    public function updatedEndDate(): void
    {
        $this->validateOnly('endDate');
        $this->resetPage();
    }

    public function updatedReferenceSearch(): void
    {
        $this->validateOnly('referenceSearch');
        $this->resetPage();
    }

    public function showDetails(int $eventId): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'error-logs');

        $event = ErrorEvent::query()->findOrFail($eventId);
        $this->selectedEventId = (int) $event->getKey();
    }

    public function closeDetails(): void
    {
        $this->selectedEventId = null;
    }

    protected function rules(): array
    {
        return [
            'statusFilter' => ['nullable', Rule::in(['403', '500'])],
            'userFilter' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'moduleFilter' => ['nullable', 'string', Rule::exists('modules', 'slug')],
            'startDate' => ['nullable', 'date_format:Y-m-d'],
            'endDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:startDate'],
            'referenceSearch' => ['nullable', 'string', 'max:9'],
        ];
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'error-logs');

        $events = ErrorEvent::query()
            ->with('user:id,name,email')
            ->when(in_array($this->statusFilter, ['403', '500'], true), function (Builder $query): void {
                $query->where('status_code', (int) $this->statusFilter);
            })
            ->when(
                $this->userFilter !== null && ctype_digit($this->userFilter),
                fn (Builder $query): Builder => $query->where('user_id', (int) $this->userFilter),
            )
            ->when(
                $this->moduleFilter !== null,
                fn (Builder $query): Builder => $query->where('module_slug', $this->moduleFilter),
            )
            ->when($this->isValidDate($this->startDate), function (Builder $query): void {
                $query->whereDate('created_at', '>=', $this->startDate);
            })
            ->when($this->isValidDate($this->endDate), function (Builder $query): void {
                $query->whereDate('created_at', '<=', $this->endDate);
            })
            ->when(trim($this->referenceSearch) !== '', function (Builder $query): void {
                $query->where('reference', 'like', '%'.trim($this->referenceSearch).'%');
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.error-logs', [
            'events' => $events,
            'modules' => Module::query()->orderBy('name')->get(['slug', 'name']),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'selectedEvent' => $this->selectedEventId === null
                ? null
                : ErrorEvent::query()->with('user:id,name,email')->find($this->selectedEventId),
        ]);
    }

    private function isValidDate(?string $date): bool
    {
        if ($date === null) {
            return false;
        }

        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsedDate !== false && $parsedDate->format('Y-m-d') === $date;
    }
}
