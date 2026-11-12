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
                'maintenance' => 'full',
                'forecasting' => 'full',
                'users-roles' => 'full',
                'settings' => 'full',
                'error-logs' => 'full',
                'invoicing' => 'full',
                'revenue' => 'full',
                'requests' => 'full',
                'calendar' => 'full',
            ],
            'production-manager' => [
                'dashboard' => 'full',
                'production' => 'full',
                'inventory' => 'view',
                'orders' => 'view',
                'maintenance' => 'view',
                'forecasting' => 'full',
                'users-roles' => 'none',
                'settings' => 'none',
                'error-logs' => 'none',
                'invoicing' => 'none',
                'revenue' => 'none',
                'requests' => 'full',
                'calendar' => 'full',
            ],
            'production-staff' => [
                'dashboard' => 'view',
                'production' => 'full',
                'inventory' => 'none',
                'orders' => 'none',
                'maintenance' => 'view',
                'forecasting' => 'none',
                'users-roles' => 'none',
                'settings' => 'none',
                'error-logs' => 'none',
                'invoicing' => 'none',
                'revenue' => 'none',
                'requests' => 'full',
                'calendar' => 'view',
            ],
            'inventory-staff' => [
                'dashboard' => 'view',
                'production' => 'none',
                'inventory' => 'full',
                'orders' => 'full',
                'maintenance' => 'none',
                'forecasting' => 'none',
                'users-roles' => 'none',
                'settings' => 'none',
                'error-logs' => 'none',
                'invoicing' => 'none',
                'revenue' => 'none',
                'requests' => 'full',
                'calendar' => 'view',
            ],
            'maintenance-staff' => [
                'dashboard' => 'view',
                'production' => 'none',
                'inventory' => 'none',
                'orders' => 'none',
                'maintenance' => 'full',
                'forecasting' => 'none',
                'users-roles' => 'none',
                'settings' => 'none',
                'error-logs' => 'none',
                'invoicing' => 'none',
                'revenue' => 'none',
                'requests' => 'full',
                'calendar' => 'view',
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
