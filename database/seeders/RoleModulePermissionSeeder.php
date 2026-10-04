<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleModulePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'administrator' => [
                'dashboard' => 'full',
                'production' => 'full',
                'inventory' => 'full',
                'orders' => 'full',
                'equipment' => 'full',
                'forecasting' => 'full',
                'users-roles' => 'full',
                'audit-logs' => 'full',
                'error-logs' => 'full',
            ],
            'production-manager' => [
                'dashboard' => 'full',
                'production' => 'full',
                'inventory' => 'view',
                'orders' => 'view',
                'equipment' => 'none',
                'forecasting' => 'full',
                'users-roles' => 'none',
                'audit-logs' => 'none',
                'error-logs' => 'none',
            ],
            'production-staff' => [
                'dashboard' => 'view',
                'production' => 'full',
                'inventory' => 'none',
                'orders' => 'none',
                'equipment' => 'none',
                'forecasting' => 'none',
                'users-roles' => 'none',
                'audit-logs' => 'none',
                'error-logs' => 'none',
            ],
            'inventory-staff' => [
                'dashboard' => 'view',
                'production' => 'none',
                'inventory' => 'full',
                'orders' => 'full',
                'equipment' => 'none',
                'forecasting' => 'none',
                'users-roles' => 'none',
                'audit-logs' => 'none',
                'error-logs' => 'none',
            ],
            'maintenance-staff' => [
                'dashboard' => 'view',
                'production' => 'none',
                'inventory' => 'none',
                'orders' => 'none',
                'equipment' => 'full',
                'forecasting' => 'none',
                'users-roles' => 'none',
                'audit-logs' => 'none',
                'error-logs' => 'none',
            ],
        ];

        $roleIds = Role::query()->pluck('id', 'slug');
        $moduleIds = Module::query()->pluck('id', 'slug');
        $rows = [];

        foreach ($permissions as $roleSlug => $modulePermissions) {
            foreach ($modulePermissions as $moduleSlug => $accessLevel) {
                $rows[] = [
                    'role_id' => $roleIds[$roleSlug],
                    'module_id' => $moduleIds[$moduleSlug],
                    'access_level' => $accessLevel,
                ];
            }
        }

        DB::table('role_module_permissions')->upsert(
            $rows,
            ['role_id', 'module_id'],
            ['access_level'],
        );
    }
}
