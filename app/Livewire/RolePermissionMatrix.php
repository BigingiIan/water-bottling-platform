<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Support\ModuleAccess;
use App\Support\Plural;
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

    /**
     * Staged access levels keyed by "{roleId}-{moduleId}", saved together by savePermissions().
     *
     * @var array<string, string>
     */
    public array $pendingPermissions = [];

    public bool $showSaveConfirmation = false;

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
            // Every role may send and receive requests and read the business calendar; everything else starts closed.
            $permissions = Module::query()
                ->orderBy('id')
                ->pluck('slug', 'id')
                ->map(fn (string $slug, int $moduleId): array => [
                    'role_id' => $newRole->id,
                    'module_id' => $moduleId,
                    'access_level' => match ($slug) {
                        'requests' => 'full',
                        'calendar' => 'view',
                        default => 'none',
                    },
                ])
                ->values()
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

    /**
     * Stage an access level for one role/module cell. Nothing is persisted until savePermissions().
     */
    public function setPermission(int $roleId, int $moduleId, string $accessLevel): void
    {
        $this->authorizeRoleManagement();

        $storedAccessLevel = $this->validatedStoredAccessLevel($roleId, $moduleId, $accessLevel);
        $cellKey = $this->permissionKey($roleId, $moduleId);

        if ($accessLevel === $storedAccessLevel) {
            unset($this->pendingPermissions[$cellKey]);

            return;
        }

        $this->pendingPermissions[$cellKey] = $accessLevel;
    }

    public function discardPermissionChanges(): void
    {
        $this->pendingPermissions = [];
        $this->showSaveConfirmation = false;
    }

    public function openSaveConfirmation(): void
    {
        $this->authorizeRoleManagement();

        if ($this->pendingPermissions === []) {
            return;
        }

        $this->showSaveConfirmation = true;
    }

    public function closeSaveConfirmation(): void
    {
        $this->showSaveConfirmation = false;
    }

    public function savePermissions(): void
    {
        $this->authorizeRoleManagement();

        $changes = collect($this->pendingPermissions)
            ->map(function (mixed $accessLevel, int|string $cellKey): array {
                [$roleId, $moduleId] = $this->parsePermissionKey((string) $cellKey);

                return ['role_id' => $roleId, 'module_id' => $moduleId, 'access_level' => (string) $accessLevel];
            })
            ->values();

        foreach ($changes as $change) {
            $this->validatedStoredAccessLevel($change['role_id'], $change['module_id'], $change['access_level']);
        }

        $savedCount = DB::transaction(function () use ($changes): int {
            $savedCount = 0;

            foreach ($changes as $change) {
                $storedAccessLevel = $this->validatedStoredAccessLevel(
                    $change['role_id'],
                    $change['module_id'],
                    $change['access_level'],
                    lockForUpdate: true,
                );

                if ($storedAccessLevel === $change['access_level']) {
                    continue;
                }

                $updatedRows = DB::table('role_module_permissions')
                    ->where('role_id', $change['role_id'])
                    ->where('module_id', $change['module_id'])
                    ->update(['access_level' => $change['access_level']]);

                if ($updatedRows !== 1) {
                    throw new \RuntimeException('The existing role/module permission could not be updated.');
                }

                $savedCount++;
            }

            return $savedCount;
        });

        $this->pendingPermissions = [];
        $this->showSaveConfirmation = false;
        $this->dispatch('permissions-saved', message: Plural::count($savedCount, 'permission change').' saved.');
    }

    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'users-roles');

        $modules = Module::query()->orderBy('name')->get();
        $roles = Role::query()
            ->withCount([
                'users',
                'modules as accessible_modules_count' => fn ($query) => $query->where('role_module_permissions.access_level', '!=', 'none'),
            ])
            ->orderBy('name')
            ->get();
        $storedPermissions = DB::table('role_module_permissions')
            ->get(['role_id', 'module_id', 'access_level'])
            ->mapWithKeys(fn (object $permission): array => [
                $this->permissionKey((int) $permission->role_id, (int) $permission->module_id) => $permission->access_level,
            ])
            ->all();
        $accessPresentation = [
            'none' => ['label' => 'None', 'description' => 'No access', 'icon' => 'no-symbol'],
            'view' => ['label' => 'View', 'description' => 'Can view only', 'icon' => 'eye'],
            'full' => ['label' => 'Full', 'description' => 'Can view and change', 'icon' => 'pencil-square'],
        ];

        return view('livewire.role-permission-matrix', [
            'modules' => $modules,
            'roles' => $roles,
            'storedPermissions' => $storedPermissions,
            'accessLevels' => self::ACCESS_LEVELS,
            'pendingChangeSummary' => $this->pendingChangeSummary($roles, $modules, $storedPermissions, $accessPresentation),
            'deletingRole' => $deletingRole = $this->deleteRolePreview($this->deletingRoleId),
            'deletingRoleUsers' => $this->deleteRolePreviewUsers($deletingRole),
            'deletingRoleUsersUrl' => $this->deleteRoleUsersUrl($deletingRole),
            'canManageRoles' => app(ModuleAccess::class)->can($user, 'users-roles', write: true),
            'rolePresentation' => [
                'administrator' => ['label' => 'Admin', 'class' => 'bg-danger-soft text-danger-ink'],
                'production-manager' => ['label' => 'Prod. Mgr', 'class' => 'bg-accent-soft text-accent-ink'],
                'production-staff' => ['label' => 'Prod. Staff', 'class' => 'bg-tag-soft text-tag-ink'],
                'inventory-staff' => ['label' => 'Inventory', 'class' => 'bg-warning-soft text-warning-ink'],
                'maintenance-staff' => ['label' => 'Maintenance', 'class' => 'bg-highlight-soft text-highlight-ink'],
            ],
            'accessPresentation' => $accessPresentation,
        ]);
    }

    /**
     * Human-readable list of staged changes for the save confirmation.
     *
     * @param  Collection<int, Role>  $roles
     * @param  Collection<int, Module>  $modules
     * @param  array<string, string>  $storedPermissions
     * @param  array<string, array{label: string, description: string, icon: string}>  $accessPresentation
     * @return list<array{role: string, module: string, from: string, to: string}>
     */
    private function pendingChangeSummary(Collection $roles, Collection $modules, array $storedPermissions, array $accessPresentation): array
    {
        $rolesById = $roles->keyBy('id');
        $modulesById = $modules->keyBy('id');
        $summary = [];

        foreach ($this->pendingPermissions as $cellKey => $accessLevel) {
            if (preg_match('/^(\d+)-(\d+)$/', (string) $cellKey, $matches) !== 1) {
                continue;
            }

            $role = $rolesById->get((int) $matches[1]);
            $module = $modulesById->get((int) $matches[2]);

            if ($role === null || $module === null || ! isset($accessPresentation[$accessLevel])) {
                continue;
            }

            $summary[] = [
                'role' => $role->name,
                'module' => $module->name,
                'from' => $accessPresentation[$storedPermissions[$cellKey] ?? 'none']['label'],
                'to' => $accessPresentation[$accessLevel]['label'],
            ];
        }

        return $summary;
    }

    /**
     * Validate a requested cell change and return the access level currently stored for it.
     */
    private function validatedStoredAccessLevel(int $roleId, int $moduleId, string $accessLevel, bool $lockForUpdate = false): string
    {
        Validator::make([
            'role_id' => $roleId,
            'module_id' => $moduleId,
            'access_level' => $accessLevel,
        ], [
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'module_id' => ['required', 'integer', Rule::exists('modules', 'id')],
            'access_level' => ['required', Rule::in(self::ACCESS_LEVELS)],
        ])->validate();

        $permission = DB::table('role_module_permissions')
            ->where('role_id', $roleId)
            ->where('module_id', $moduleId)
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->first();

        if ($permission === null) {
            abort(404, 'The role/module permission does not exist.');
        }

        $currentIndex = array_search($permission->access_level, self::ACCESS_LEVELS, true);

        if ($currentIndex === false) {
            throw new \UnexpectedValueException('The stored permission access level is invalid.');
        }

        $role = Role::query()->findOrFail($roleId);

        abort_if(
            $role->slug === 'administrator' && array_search($accessLevel, self::ACCESS_LEVELS, true) < $currentIndex,
            403,
            'Administrator permissions cannot be reduced.',
        );

        return $permission->access_level;
    }

    private function permissionKey(int $roleId, int $moduleId): string
    {
        return "{$roleId}-{$moduleId}";
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parsePermissionKey(string $cellKey): array
    {
        abort_unless(preg_match('/^(\d+)-(\d+)$/', $cellKey, $matches) === 1, 422, 'Invalid permission change.');

        return [(int) $matches[1], (int) $matches[2]];
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
