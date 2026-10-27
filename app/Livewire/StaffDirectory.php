<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Services\UserPreferenceService;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('components.app-layout', ['title' => 'Staff Directory', 'activeItem' => 'Users & Roles'])]
class StaffDirectory extends Component
{
    use WithPagination;

    private const RECENT_ACTIVITY_LIMIT = 8;

    public string $search = '';

    #[Url(as: 'role', except: '')]
    public string $roleFilter = '';

    public string $statusFilter = '';

    public string $moduleFilter = '';

    public ?int $openMenuUserId = null;

    /**
     * The staff member shown in the detail panel; kept in the URL so the panel can be linked to.
     */
    #[Url(as: 'user', except: null)]
    public ?int $selectedUserId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedModuleFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'roleFilter', 'statusFilter', 'moduleFilter']);
        $this->resetPage();
    }

    #[On('user-created')]
    public function refreshList(): void
    {
        $this->resetPage();
    }

    #[On('user-changed')]
    public function closeMenus(): void
    {
        $this->openMenuUserId = null;
    }

    public function openUser(int $userId): void
    {
        $this->openMenuUserId = null;
        $this->selectedUserId = User::query()->whereKey($userId)->exists() ? $userId : null;
    }

    public function closeUser(): void
    {
        $this->selectedUserId = null;
    }

    public function toggleMenu(int $userId): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles', write: true);

        $this->openMenuUserId = $this->openMenuUserId === $userId ? null : $userId;
    }

    public function exportCsv(): StreamedResponse
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles');

        $members = $this->filteredUsersQuery()->get();

        return response()->streamDownload(function () use ($members): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['name', 'email', 'role', 'status', 'modules', 'last_active_at']);

            foreach ($members as $member) {
                fputcsv($output, [
                    $member->name,
                    $member->email,
                    $member->role?->name,
                    $member->status,
                    $member->role?->modules
                        ->filter(fn (Module $module): bool => $module->pivot->access_level !== 'none')
                        ->map(fn (Module $module): string => "{$module->name} ({$module->pivot->access_level})")
                        ->implode('; '),
                    $member->last_active_at?->toIso8601String(),
                ]);
            }

            fclose($output);
        }, 'staff-directory-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles');

        $users = $this->filteredUsersQuery()
            ->paginate(app(UserPreferenceService::class)->pageSize($user, 6));
        $selectedMember = $this->selectedUserId === null
            ? null
            : User::query()->with(['role.modules'])->find($this->selectedUserId);

        return view('livewire.staff-directory', [
            'users' => $users,
            'selectedMember' => $selectedMember,
            'selectedMemberActivity' => $selectedMember === null ? collect() : $this->recentActivityFor($selectedMember),
            'roles' => Role::query()->orderBy('name')->get(),
            'modules' => Module::query()->orderBy('name')->get(),
            'canInvite' => app(ModuleAccess::class)->can($user, 'users-roles', write: true),
            'rolePresentation' => [
                'administrator' => ['class' => 'bg-red-50 text-[#b91c1c]', 'avatarColor' => '#2563eb'],
                'production-manager' => ['class' => 'bg-blue-50 text-[#1d4ed8]', 'avatarColor' => '#0f766e'],
                'production-staff' => ['class' => 'bg-teal-50 text-[#0f766e]', 'avatarColor' => '#0f766e'],
                'inventory-staff' => ['class' => 'bg-amber-100 text-[#92400e]', 'avatarColor' => '#b45309'],
                'maintenance-staff' => ['class' => 'bg-purple-100 text-[#7e22ce]', 'avatarColor' => '#7e22ce'],
            ],
            'statusPresentation' => [
                'active' => ['label' => 'Active', 'class' => 'bg-green-50 text-[#15803d]', 'dotClass' => 'bg-green-500'],
                'pending' => ['label' => 'Pending Invite', 'class' => 'bg-amber-50 text-[#b45309]', 'dotClass' => 'bg-amber-500'],
                'suspended' => ['label' => 'Suspended', 'class' => 'bg-red-50 text-[#b91c1c]', 'dotClass' => 'bg-red-500'],
            ],
        ]);
    }

    /**
     * Audit entries the staff member made or that were made about them, newest first.
     *
     * @return Collection<int, AuditLog>
     */
    private function recentActivityFor(User $member): Collection
    {
        return AuditLog::query()
            ->with(['user:id,name', 'targetUser:id,name'])
            ->where(fn (Builder $query) => $query
                ->where('user_id', $member->id)
                ->orWhere('target_user_id', $member->id))
            ->latest('timestamp')
            ->latest('id')
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get();
    }

    /**
     * The directory filters shared by the table and the CSV export.
     *
     * @return Builder<User>
     */
    private function filteredUsersQuery(): Builder
    {
        return User::query()
            ->with(['role.modules'])
            ->when($this->search !== '', function (Builder $query): void {
                $search = trim($this->search);

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('role', function (Builder $query) use ($search): void {
                            $query->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($this->roleFilter !== '', function (Builder $query): void {
                $query->where('role_id', $this->roleFilter);
            })
            ->when(in_array($this->statusFilter, ['active', 'pending', 'suspended'], true), function (Builder $query): void {
                $query->where('status', $this->statusFilter);
            })
            ->when($this->moduleFilter !== '', function (Builder $query): void {
                $query->whereHas('role.modules', function (Builder $query): void {
                    $query->where('modules.slug', $this->moduleFilter)
                        ->where('role_module_permissions.access_level', '!=', 'none');
                });
            })
            ->orderBy('name');
    }
}
