<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The placeholder "equipment" module becomes the built "maintenance" module. The row and its
     * permissions are kept; production roles gain view access so they can see equipment and line status.
     */
    public function up(): void
    {
        $moduleId = DB::table('modules')->whereIn('slug', ['equipment', 'maintenance'])->value('id')
            ?? DB::table('modules')->insertGetId(['slug' => 'maintenance', 'name' => 'Maintenance', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('modules')->where('id', $moduleId)->update([
            'slug' => 'maintenance',
            'name' => 'Maintenance',
            'description' => 'Equipment, preventive maintenance plans and work orders',
            'updated_at' => now(),
        ]);

        foreach (DB::table('roles')->get(['id', 'slug']) as $role) {
            $level = match (true) {
                in_array($role->slug, ['administrator', 'maintenance-staff'], true) => 'full',
                str_starts_with($role->slug, 'production') => 'view',
                default => null,
            };

            if ($level === null) {
                DB::table('role_module_permissions')->insertOrIgnore(['role_id' => $role->id, 'module_id' => $moduleId, 'access_level' => 'none']);

                continue;
            }

            DB::table('role_module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                ['access_level' => $level],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('modules')->where('slug', 'maintenance')->update([
            'slug' => 'equipment',
            'name' => 'Equipment',
            'description' => 'Machine maintenance tasks',
        ]);
    }
};
