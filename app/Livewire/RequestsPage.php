<?php

namespace App\Livewire;

use App\Models\DepartmentRequest;
use App\Models\User;
use App\Services\Requests\RequestAccess;
use App\Services\Requests\RequestPriority;
use App\Services\Requests\RequestStatus;
use App\Services\Requests\RequestType;
use App\Services\UserPreferenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Requests', 'breadcrumbSection' => 'Home', 'activeItem' => 'Requests'])]
class RequestsPage extends Component
{
    use WithPagination;

    private const TABS = ['inbox', 'sent', 'all'];

    #[Url(as: 'tab', except: 'inbox')]
    public string $activeTab = 'inbox';

    #[Url(as: 'status', except: 'active')]
    public string $statusFilter = 'active';

    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

    #[Url(as: 'priority', except: '')]
    public string $priorityFilter = '';

    #[Url(as: 'overdue', except: false)]
    public bool $overdueOnly = false;

    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->normaliseTab();
    }

    public function updated(string $property): void
    {
        if ($property === 'activeTab') {
            $this->normaliseTab();
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['statusFilter', 'typeFilter', 'priorityFilter', 'overdueOnly', 'search']);
        $this->resetPage();
    }

    #[On('request-sent')]
    public function refreshList(): void
    {
        $this->resetPage();
    }

    public function render(RequestAccess $access): View
    {
        $user = $this->authorizedUser($access);
        $requests = $this->filteredQuery($user)
            ->with(['sender:id,name', 'toRole:id,name', 'assignee:id,name'])
            ->withCount('comments')
            ->orderByRaw("CASE WHEN status IN ('open', 'acknowledged', 'in_progress') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")
            ->latest()
            ->paginate(app(UserPreferenceService::class)->pageSize($user, 15));

        return view('livewire.requests-page', [
            'requests' => $requests,
            'isAdministrator' => $access->isAdministrator($user),
            'canSend' => $access->canSendRequests($user),
            'inboxActiveCount' => DepartmentRequest::query()->openFor($user)->count(),
            'statuses' => RequestStatus::cases(),
            'types' => RequestType::cases(),
            'priorities' => RequestPriority::cases(),
            'hasActiveFilters' => $this->statusFilter !== 'active' || $this->typeFilter !== '' || $this->priorityFilter !== '' || $this->overdueOnly || $this->search !== '',
        ]);
    }

    /**
     * @return Builder<DepartmentRequest>
     */
    private function filteredQuery(User $user): Builder
    {
        $query = DepartmentRequest::query()->visibleTo($user);

        match ($this->activeTab) {
            'sent' => $query->where('from_user_id', $user->id),
            'all' => $query,
            default => $query->where('to_role_id', $user->role_id),
        };

        return $query
            ->when($this->statusFilter === 'active', fn (Builder $query) => $query->whereIn('status', RequestStatus::activeValues()))
            ->when(RequestStatus::tryFrom($this->statusFilter) !== null, fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->when(RequestType::tryFrom($this->typeFilter) !== null, fn (Builder $query) => $query->where('type', $this->typeFilter))
            ->when(RequestPriority::tryFrom($this->priorityFilter) !== null, fn (Builder $query) => $query->where('priority', $this->priorityFilter))
            ->when($this->overdueOnly, fn (Builder $query) => $query->overdue())
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->where(fn (Builder $query) => $query
                    ->where('reference', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%"));
            });
    }

    private function normaliseTab(): void
    {
        $user = auth()->user();
        $isAdministrator = $user instanceof User && $user->role?->slug === 'administrator';

        if (! in_array($this->activeTab, self::TABS, true) || ($this->activeTab === 'all' && ! $isAdministrator)) {
            $this->activeTab = 'inbox';
        }
    }

    private function authorizedUser(RequestAccess $access): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $access->canUseRequests($user), 403);

        return $user;
    }
}
