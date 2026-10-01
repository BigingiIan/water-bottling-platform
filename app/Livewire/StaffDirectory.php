<?php

namespace App\Livewire;

use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.app-layout', ['title' => 'Staff Directory', 'activeItem' => 'Users & Roles'])]
class StaffDirectory extends Component
{
    use WithPagination;

    public string $search = '';

    public string $roleFilter = '';

    public string $statusFilter = '';

    public string $moduleFilter = '';

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

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles');

        $users = User::query()
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
            ->orderBy('name')
            ->paginate(6);

        return view('livewire.staff-directory', [
            'users' => $users,
            'roles' => Role::query()->orderBy('name')->get(),
            'modules' => Module::query()->orderBy('name')->get(),
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
}
