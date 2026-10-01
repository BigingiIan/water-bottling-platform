<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleModulePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ModuleAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            ModuleSeeder::class,
            RoleModulePermissionSeeder::class,
        ]);

        Route::middleware(['web', 'auth', 'module.access:dashboard'])
            ->get('/_test/dashboard-read', fn () => response('ok'));
        Route::middleware(['web', 'auth', 'module.access:dashboard'])
            ->post('/_test/dashboard-write', fn () => response('ok'));
    }

    public function test_view_access_permits_safe_requests(): void
    {
        $productionStaff = Role::query()->where('slug', 'production-staff')->firstOrFail();
        $user = User::factory()->create(['role_id' => $productionStaff->id]);

        $this->actingAs($user)->get('/_test/dashboard-read')->assertOk();
    }

    public function test_view_access_forbids_write_requests(): void
    {
        $productionStaff = Role::query()->where('slug', 'production-staff')->firstOrFail();
        $user = User::factory()->create(['role_id' => $productionStaff->id]);

        $this->actingAs($user)->post('/_test/dashboard-write')->assertForbidden();
    }

    public function test_full_access_permits_write_requests(): void
    {
        $administrator = Role::query()->where('slug', 'administrator')->firstOrFail();
        $user = User::factory()->create(['role_id' => $administrator->id]);

        $this->actingAs($user)->post('/_test/dashboard-write')->assertOk();
    }

    public function test_missing_permission_row_is_forbidden(): void
    {
        $productionStaff = Role::query()->where('slug', 'production-staff')->firstOrFail();
        $dashboard = Module::query()->where('slug', 'dashboard')->firstOrFail();
        DB::table('role_module_permissions')
            ->where('role_id', $productionStaff->id)
            ->where('module_id', $dashboard->id)
            ->delete();

        $user = User::factory()->create(['role_id' => $productionStaff->id]);

        $this->actingAs($user)->get('/_test/dashboard-read')->assertForbidden();
    }
}
