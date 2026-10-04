<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModuleAccess
{
    public function can(mixed $user, string $moduleSlug, bool $write = false): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $accessLevel = $this->accessLevel($user, $moduleSlug);

        if ($accessLevel === null || $accessLevel === 'none') {
            return false;
        }

        return ! $write || $accessLevel === 'full';
    }

    public function authorize(User $user, string $moduleSlug, bool $write = false): void
    {
        if ($this->can($user, $moduleSlug, $write)) {
            return;
        }

        $moduleName = DB::table('modules')->where('slug', $moduleSlug)->value('name') ?? Str::headline($moduleSlug);
        $accessLevel = $this->accessLevel($user, $moduleSlug);

        if ($accessLevel === null || $accessLevel === 'none') {
            abort(403, 'no_access:'.$moduleName);
        }

        abort(403, 'read_only:'.$moduleName);
    }

    private function accessLevel(User $user, string $moduleSlug): ?string
    {
        $module = DB::table('modules')
            ->leftJoin('role_module_permissions', function (JoinClause $join) use ($user): void {
                $join->on('modules.id', '=', 'role_module_permissions.module_id')
                    ->where('role_module_permissions.role_id', '=', $user->role_id);
            })
            ->where('modules.slug', $moduleSlug)
            ->first(['role_module_permissions.access_level']);

        return $module?->access_level;
    }
}
