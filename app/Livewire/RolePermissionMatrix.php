<?php

namespace App\Livewire;

use App\Models\Module;
use App\Models\Role;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Role & Permissions', 'activeItem' => 'Users & Roles'])]
class RolePermissionMatrix extends Component
{
    private const ACCESS_LEVELS = ['none', 'view', 'full'];

    public function cyclePermission(int $roleId, int $moduleId): void
    {
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

        $updatedRows = DB::table('role_module_permissions')
            ->where('role_id', $roleId)
            ->where('module_id', $moduleId)
            ->update([
                'access_level' => self::ACCESS_LEVELS[($currentIndex + 1) % count(self::ACCESS_LEVELS)],
            ]);

        if ($updatedRows !== 1) {
            throw new \RuntimeException('The existing role/module permission could not be updated.');
        }
    }

    public function render(): View
    {
        return view('livewire.role-permission-matrix', [
            'modules' => Module::query()->with('roles')->orderBy('name')->get(),
            'roles' => Role::query()->orderBy('name')->get(),
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
}
