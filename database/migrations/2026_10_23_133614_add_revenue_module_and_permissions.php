<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds the Revenue module: administrators get full access, every other role none.
     */
    public function up(): void
    {
        $moduleId = DB::table('modules')->where('slug', 'revenue')->value('id')
            ?? DB::table('modules')->insertGetId([
                'name' => 'Revenue',
                'slug' => 'revenue',
                'description' => 'Invoiced, collected and outstanding revenue',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach (DB::table('roles')->get(['id', 'slug']) as $role) {
            DB::table('role_module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                ['access_level' => $role->slug === 'administrator' ? 'full' : 'none'],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $moduleId = DB::table('modules')->where('slug', 'revenue')->value('id');

        if ($moduleId !== null) {
            DB::table('role_module_permissions')->where('module_id', $moduleId)->delete();
            DB::table('modules')->where('id', $moduleId)->delete();
        }
    }
};
