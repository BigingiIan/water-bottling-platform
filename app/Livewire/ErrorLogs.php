<?php

namespace App\Livewire;

use App\Models\ErrorEvent;
use App\Models\Module;
use App\Models\User;
use App\Services\UserPreferenceService;
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

    public string $viewMode = 'events';

    public string $resolutionNote = '';

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
        $this->resolutionNote = app(ModuleAccess::class)->can($user, 'error-logs', write: true)
            ? $event->resolution_note ?? ''
            : '';
    }

    public function showGroupDetails(string $fingerprint): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'error-logs');
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $fingerprint) === 1, 404);

        $event = ErrorEvent::query()
            ->where('fingerprint', $fingerprint)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail();

        $this->showDetails((int) $event->getKey());
    }

    public function showGroups(): void
    {
        $this->viewMode = 'groups';
        $this->resetPage();
    }

    public function showEvents(): void
    {
        $this->viewMode = 'events';
        $this->resetPage();
    }

    public function updateResolution(bool $resolved): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'error-logs', write: true);
        abort_if($this->selectedEventId === null, 404);

        $validated = $this->validate([
            'resolutionNote' => ['nullable', 'string', 'max:2000'],
        ]);

        $event = ErrorEvent::query()->findOrFail($this->selectedEventId);
        $event->forceFill([
            'resolved_at' => $resolved ? now() : null,
            'resolution_note' => trim($validated['resolutionNote'] ?? '') ?: null,
        ])->save();

        $this->resolutionNote = $event->resolution_note ?? '';
    }

    public function closeDetails(): void
    {
        $this->selectedEventId = null;
        $this->resolutionNote = '';
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

        $pageSize = app(UserPreferenceService::class)->pageSize($user, 15);
        $query = ErrorEvent::query();
        $this->applyFilters($query);

        $groups = null;
        $events = null;

        if ($this->viewMode === 'groups') {
            $groups = (clone $query)
                ->whereNotNull('fingerprint')
                ->selectRaw('fingerprint, MIN(exception_class) as exception_class, COUNT(*) as event_count, MIN(created_at) as first_seen, MAX(created_at) as last_seen')
                ->groupBy('fingerprint')
                ->orderByDesc('last_seen')
                ->paginate($pageSize);
        } else {
            $events = $query
                ->with('user:id,name,email')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($pageSize);
        }

        return view('livewire.error-logs', [
            'events' => $events,
            'groups' => $groups,
            'canViewSensitiveDetails' => app(ModuleAccess::class)->can($user, 'error-logs', write: true),
            'modules' => Module::query()->orderBy('name')->get(['slug', 'name']),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'selectedEvent' => $this->selectedEventId === null
                ? null
                : ErrorEvent::query()->with('user:id,name,email')->find($this->selectedEventId),
        ]);
    }

    private function applyFilters(Builder $query): void
    {
        $query
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
            });
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
