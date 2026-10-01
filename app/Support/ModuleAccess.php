<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ModuleAccess
{
    public function authorize(User $user, string $moduleSlug, bool $write = false): void
    {
        $accessLevel = DB::table('role_module_permissions')
            ->join('modules', 'modules.id', '=', 'role_module_permissions.module_id')
            ->where('role_module_permissions.role_id', $user->role_id)
            ->where('modules.slug', $moduleSlug)
            ->value('role_module_permissions.access_level');

        abort_if(
            $accessLevel === null
                || $accessLevel === 'none'
                || ($write && $accessLevel !== 'full'),
            403,
        );
    }
}
