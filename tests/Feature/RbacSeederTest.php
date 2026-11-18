<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Role;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RbacSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_error_logs_migration_provisions_module_and_default_permissions_for_existing_roles(): void
    {
        $errorLogs = Module::query()->where('slug', 'error-logs')->firstOrFail();
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();

        $this->assertDatabaseHas('role_module_permissions', [
            'role_id' => $administrator->id,
            'module_id' => $errorLogs->id,
            'access_level' => 'full',
        ]);
        $this->assertDatabaseHas('role_module_permissions', [
            'role_id' => $productionManager->id,
            'module_id' => $errorLogs->id,
            'access_level' => 'none',
        ]);
        $this->assertSame(3, DB::table('role_module_permissions')->where('module_id', $errorLogs->id)->count());
    }

    public function test_database_seeder_creates_roles_modules_and_a_complete_permission_matrix(): void
    {
        $this->seed();

        $this->assertDatabaseCount('roles', 5);
        $this->assertDatabaseCount('modules', 13);
        $this->assertDatabaseCount('role_module_permissions', 65);
        $this->assertSame(
            [
                'Administrator',
                'Inventory Staff',
                'Maintenance Staff',
                'Production Manager',
                'Production Staff',
            ],
            Role::query()->orderBy('name')->pluck('name')->all(),
        );

        $this->assertSame(
            [
                'Business Calendar',
                'Dashboard',
                'Error Logs',
                'Forecasting',
                'Inventory',
                'Invoicing',
                'Maintenance',
                'Orders',
                'Production',
                'Requests',
                'Revenue',
                'Settings',
                'Users & Roles',
            ],
            Module::query()->orderBy('name')->pluck('name')->all(),
        );

        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $this->assertSame(
            13,
            $administrator->modules()->wherePivot('access_level', 'full')->count(),
        );
        $this->assertSame(
            'full',
            $administrator->modules()->where('slug', 'error-logs')->firstOrFail()->pivot->access_level,
        );
        $this->assertSame(
            'full',
            $administrator->modules()->where('slug', 'settings')->firstOrFail()->pivot->access_level,
        );

        $productionManager = Role::query()->where('slug', 'production-manager')->firstOrFail();
        $this->assertSame(
            'full',
            $productionManager->modules()->where('slug', 'production')->firstOrFail()->pivot->access_level,
        );
        $this->assertSame(
            'full',
            $productionManager->modules()->where('slug', 'forecasting')->firstOrFail()->pivot->access_level,
        );
        $this->assertSame(
            'view',
            $productionManager->modules()->where('slug', 'inventory')->firstOrFail()->pivot->access_level,
        );
        $this->assertSame(
            'view',
            $productionManager->modules()->where('slug', 'orders')->firstOrFail()->pivot->access_level,
        );
        $this->assertSame(
            'view',
            $productionManager->modules()->where('slug', 'maintenance')->firstOrFail()->pivot->access_level,
        );
        $this->assertDatabaseHas('role_module_permissions', [
            'role_id' => $productionManager->id,
            'module_id' => Module::query()->where('slug', 'users-roles')->valueOrFail('id'),
            'access_level' => 'none',
        ]);
        $this->assertDatabaseHas('role_module_permissions', [
            'role_id' => $productionManager->id,
            'module_id' => Module::query()->where('slug', 'error-logs')->valueOrFail('id'),
            'access_level' => 'none',
        ]);
        $this->assertDatabaseHas('role_module_permissions', [
            'role_id' => $productionManager->id,
            'module_id' => Module::query()->where('slug', 'settings')->valueOrFail('id'),
            'access_level' => 'none',
        ]);
        $this->assertSame(
            4,
            DB::table('role_module_permissions')
                ->where('module_id', Module::query()->where('slug', 'error-logs')->valueOrFail('id'))
                ->where('access_level', 'none')
                ->count(),
        );
    }

    public function test_database_seeder_is_idempotent_for_rbac_records(): void
    {
        $seeders = [
            RoleSeeder::class,
            ModuleSeeder::class,
            RoleModulePermissionSeeder::class,
        ];

        $this->seed($seeders);
        $this->seed($seeders);

        $this->assertDatabaseCount('roles', 5);
        $this->assertDatabaseCount('modules', 13);
        $this->assertDatabaseCount('role_module_permissions', 65);
        $this->assertSame(65, DB::table('role_module_permissions')->count());
    }
}
