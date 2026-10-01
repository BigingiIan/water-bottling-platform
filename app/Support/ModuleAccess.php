<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModuleAccess
{
    public function authorize(User $user, string $moduleSlug, bool $write = false): void
    {
        $module = DB::table('modules')
            ->leftJoin('role_module_permissions', function (JoinClause $join) use ($user): void {
                $join->on('modules.id', '=', 'role_module_permissions.module_id')
                    ->where('role_module_permissions.role_id', '=', $user->role_id);
            })
            ->where('modules.slug', $moduleSlug)
            ->first([
                'modules.name',
                'role_module_permissions.access_level',
            ]);

        $moduleName = $module?->name ?? Str::headline($moduleSlug);
        $accessLevel = $module?->access_level;

        if ($accessLevel === null || $accessLevel === 'none') {
            abort(403, 'no_access:'.$moduleName);
        }

        if ($write && $accessLevel !== 'full') {
            abort(403, 'read_only:'.$moduleName);
        }
    }
}
