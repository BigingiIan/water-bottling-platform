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

    public function test_database_seeder_creates_roles_modules_and_a_complete_permission_matrix(): void
    {
        $this->seed();

        $this->assertDatabaseCount('roles', 5);
        $this->assertDatabaseCount('modules', 8);
        $this->assertDatabaseCount('role_module_permissions', 40);
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
                'Audit Logs',
                'Dashboard',
                'Equipment',
                'Forecasting',
                'Inventory',
                'Orders',
                'Production',
                'Users & Roles',
            ],
            Module::query()->orderBy('name')->pluck('name')->all(),
        );

        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $this->assertSame(
            8,
            $administrator->modules()->wherePivot('access_level', 'full')->count(),
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
            'none',
            $productionManager->modules()->where('slug', 'equipment')->firstOrFail()->pivot->access_level,
        );
        $this->assertDatabaseHas('role_module_permissions', [
            'role_id' => $productionManager->id,
            'module_id' => Module::query()->where('slug', 'users-roles')->valueOrFail('id'),
            'access_level' => 'none',
        ]);
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
        $this->assertDatabaseCount('modules', 8);
        $this->assertDatabaseCount('role_module_permissions', 40);
        $this->assertSame(40, DB::table('role_module_permissions')->count());
    }
}
