<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

#[Layout('components.app-layout', ['title' => 'Roles & Permissions', 'activeItem' => 'Roles & Permissions'])]
class RolePermissionMatrix extends Component
{
    private const ACCESS_LEVELS = ['none', 'view', 'full'];

    public string $name = '';

    public string $description = '';

    public bool $showRoleModal = false;

    public bool $showDeleteRoleModal = false;

    public ?string $deleteRoleError = null;

    #[Locked]
    public ?int $editingRoleId = null;

    #[Locked]
    public ?int $deletingRoleId = null;

    public function openCreateRoleModal(): void
    {
        $this->authorizeRoleManagement();
        $this->resetValidation();
        $this->reset(['name', 'description', 'editingRoleId']);
        $this->showRoleModal = true;
    }

    public function openEditRoleModal(int $roleId): void
    {
        $this->authorizeRoleManagement();
        $role = Role::query()->findOrFail($roleId);

        abort_if($role->slug === 'administrator', 403, 'The Administrator role cannot be renamed.');

        $this->resetValidation();
        $this->editingRoleId = $role->id;
        $this->name = $role->name;
        $this->description = $role->description ?? '';
        $this->showRoleModal = true;
    }

    public function closeRoleModal(): void
    {
        $this->showRoleModal = false;
    }

    public function saveRole(): void
    {
        $this->authorizeRoleManagement();

        $role = $this->editingRoleId === null
            ? null
            : Role::query()->findOrFail($this->editingRoleId);

        if ($role?->slug === 'administrator') {
            abort(403, 'The Administrator role cannot be renamed.');
        }

        $uniqueNameRule = Rule::unique('roles', 'name');

        if ($role !== null) {
            $uniqueNameRule->ignore($role->id);
        }

        $validated = Validator::make([
            'name' => $this->name,
            'description' => $this->description,
        ], [
            'name' => ['required', 'string', 'max:255', $uniqueNameRule],
            'description' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        DB::transaction(function () use ($role, $validated): void {
            if ($role !== null) {
                $lockedRole = Role::query()->lockForUpdate()->findOrFail($role->id);
                abort_if($lockedRole->slug === 'administrator', 403, 'The Administrator role cannot be renamed.');

                $lockedRole->update([
                    'name' => $validated['name'],
                    'slug' => $this->uniqueSlug($validated['name'], $lockedRole->id),
                    'description' => $validated['description'] ?? null,
                ]);

                return;
            }

            $newRole = Role::query()->create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'description' => $validated['description'] ?? null,
            ]);
            $permissions = Module::query()
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (int $moduleId): array => [
                    'role_id' => $newRole->id,
                    'module_id' => $moduleId,
                    'access_level' => 'none',
                ])
                ->all();

            if ($permissions !== []) {
                DB::table('role_module_permissions')->insert($permissions);
            }
        });

        $this->showRoleModal = false;
        $this->reset(['name', 'description', 'editingRoleId']);
    }

    public function openDeleteRoleModal(int $roleId): void
    {
        $this->authorizeRoleManagement();
        $role = Role::query()->findOrFail($roleId);

        $this->deletingRoleId = (int) $role->getKey();
        $this->deleteRoleError = null;
        $this->showDeleteRoleModal = true;
    }

    public function closeDeleteRoleModal(): void
    {
        $roleId = $this->deletingRoleId;
        $this->showDeleteRoleModal = false;
        $this->deleteRoleError = null;
        $this->deletingRoleId = null;

        if ($roleId !== null) {
            $this->dispatch('role-delete-modal-closed', roleId: $roleId);
        }
    }

    public function deleteRole(): void
    {
        $actor = $this->authorizeRoleManagement();
        abort_if($this->deletingRoleId === null, 404);
        $this->deleteRoleError = null;

        try {
            $deletedRoleName = DB::transaction(function () use ($actor): ?string {
                $role = Role::query()->lockForUpdate()->findOrFail($this->deletingRoleId);

                if ($role->isSystemRole()) {
                    $this->deleteRoleError = "'{$role->name}' is a system role and cannot be deleted.";

                    return null;
                }

                $userCount = $role->users()->count();

                if ($userCount > 0) {
                    $this->deleteRoleError = "{$userCount} users still have the role '{$role->name}'. Reassign them before deleting it.";

                    return null;
                }

                $roleName = $role->name;
                $role->delete();

                AuditLog::query()->create([
                    'user_id' => $actor->id,
                    'action' => 'roles.deleted',
                    'table_name' => 'roles',
                    'old_values' => [
                        'name' => $roleName,
                        'user_count' => $userCount,
                    ],
                    'new_values' => null,
                ]);

                return $roleName;
            });
        } catch (QueryException $exception) {
            report($exception);
            $this->deleteRoleError = 'This role could not be deleted because it changed while you were reviewing it. Refresh the page and try again.';

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->deleteRoleError = 'This role could not be deleted. Refresh the page and try again.';

            return;
        }

        if ($deletedRoleName === null) {
            return;
        }

        $this->showDeleteRoleModal = false;
        $this->deletingRoleId = null;
        $this->deleteRoleError = null;
        $this->dispatch('role-deleted', message: "Role '{$deletedRoleName}' deleted.");
    }

    public function dismissRoleDeletedToast(): void
    {
        $this->dispatch('role-deleted-toast-dismissed');
    }

    private function deleteRolePreview(?int $roleId): ?Role
    {
        if ($roleId === null) {
            return null;
        }

        $role = Role::query()->withCount('users')->find($roleId);

        if ($role !== null) {
            $role->setAttribute(
                'accessible_modules_count',
                $role->modules()->wherePivot('access_level', '!=', 'none')->count(),
            );
        }

        return $role;
    }

    /**
     * @return Collection<int, string>
     */
    private function deleteRolePreviewUsers(?Role $role): Collection
    {
        if ($role === null) {
            return collect();
        }

        return $role->users()->orderBy('name')->limit(5)->pluck('name');
    }

    private function deleteRoleUsersUrl(?Role $role): ?string
    {
        return $role === null ? null : route('staff.directory', ['role' => $role->getKey()]);
    }

    public function cyclePermission(int $roleId, int $moduleId): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles', write: true);

        Validator::make([
            'role_id' => $roleId,
            'module_id' => $moduleId,
        ], [
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'module_id' => ['required', 'integer', Rule::exists('modules', 'id')],
        ])->validate();

        $permission = DB::table('role_module_permissions')
            ->where('role_id', $roleId)
            ->where('module_id', $moduleId)
            ->first();

        if ($permission === null) {
            abort(404, 'The role/module permission does not exist.');
        }

        $currentIndex = array_search($permission->access_level, self::ACCESS_LEVELS, true);

        if ($currentIndex === false) {
            throw new \UnexpectedValueException('The stored permission access level is invalid.');
        }

        $nextIndex = ($currentIndex + 1) % count(self::ACCESS_LEVELS);
        $role = Role::query()->findOrFail($roleId);

        abort_if(
            $role->slug === 'administrator' && $nextIndex < $currentIndex,
            403,
            'Administrator permissions cannot be reduced.',
        );

        $updatedRows = DB::table('role_module_permissions')
            ->where('role_id', $roleId)
            ->where('module_id', $moduleId)
            ->update([
                'access_level' => self::ACCESS_LEVELS[$nextIndex],
            ]);

        if ($updatedRows !== 1) {
            throw new \RuntimeException('The existing role/module permission could not be updated.');
        }
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles');

        return view('livewire.role-permission-matrix', [
            'modules' => Module::query()->with('roles')->orderBy('name')->get(),
            'roles' => Role::query()->orderBy('name')->get(),
            'deletingRole' => $deletingRole = $this->deleteRolePreview($this->deletingRoleId),
            'deletingRoleUsers' => $this->deleteRolePreviewUsers($deletingRole),
            'deletingRoleUsersUrl' => $this->deleteRoleUsersUrl($deletingRole),
            'canManageRoles' => app(ModuleAccess::class)->can($user, 'users-roles', write: true),
            'rolePresentation' => [
                'administrator' => ['label' => 'Admin', 'class' => 'bg-red-50 text-[#b91c1c]'],
                'production-manager' => ['label' => 'Prod. Mgr', 'class' => 'bg-blue-50 text-[#1d4ed8]'],
                'production-staff' => ['label' => 'Prod. Staff', 'class' => 'bg-teal-50 text-[#0f766e]'],
                'inventory-staff' => ['label' => 'Inventory', 'class' => 'bg-amber-100 text-[#92400e]'],
                'maintenance-staff' => ['label' => 'Maintenance', 'class' => 'bg-purple-100 text-[#7e22ce]'],
            ],
            'accessPresentation' => [
                'none' => ['label' => 'No access', 'class' => 'bg-gray-200'],
                'view' => ['label' => 'View only', 'class' => 'bg-amber-500'],
                'full' => ['label' => 'Full access', 'class' => 'bg-blue-600'],
            ],
        ]);
    }

    private function authorizeRoleManagement(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles', write: true);

        return $user;
    }

    private function uniqueSlug(string $name, ?int $exceptRoleId = null): string
    {
        $baseSlug = Str::slug($name) ?: 'role';
        $slug = $baseSlug;
        $suffix = 2;

        while (
            Role::query()
                ->where('slug', $slug)
                ->when($exceptRoleId !== null, fn ($query) => $query->where('id', '!=', $exceptRoleId))
                ->exists()
        ) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
