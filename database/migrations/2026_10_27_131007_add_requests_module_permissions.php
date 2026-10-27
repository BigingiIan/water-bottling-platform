<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds the Requests module: every role may send requests and see its own threads.
     */
    public function up(): void
    {
        $moduleId = DB::table('modules')->where('slug', 'requests')->value('id')
            ?? DB::table('modules')->insertGetId([
                'name' => 'Requests',
                'slug' => 'requests',
                'description' => 'Ask another team to act and follow up on what was asked of yours',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach (DB::table('roles')->pluck('id') as $roleId) {
            DB::table('role_module_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'module_id' => $moduleId],
                ['access_level' => 'full'],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $moduleId = DB::table('modules')->where('slug', 'requests')->value('id');

        if ($moduleId !== null) {
            DB::table('role_module_permissions')->where('module_id', $moduleId)->delete();
            DB::table('modules')->where('id', $moduleId)->delete();
        }
    }
};
