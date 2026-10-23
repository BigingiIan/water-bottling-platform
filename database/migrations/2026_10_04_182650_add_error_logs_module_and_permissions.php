<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $timestamp = now();
        $moduleId = DB::table('modules')->where('slug', 'error-logs')->value('id');

        if ($moduleId === null) {
            $moduleId = DB::table('modules')->insertGetId([
                'name' => 'Error Logs',
                'slug' => 'error-logs',
                'description' => 'Branded application errors',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        $permissions = DB::table('roles')
            ->get(['id', 'slug'])
            ->map(fn (object $role): array => [
                'role_id' => $role->id,
                'module_id' => $moduleId,
                'access_level' => $role->slug === 'administrator' ? 'full' : 'none',
            ])
            ->all();

        if ($permissions !== []) {
            DB::table('role_module_permissions')->insertOrIgnore($permissions);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $moduleId = DB::table('modules')->where('slug', 'error-logs')->value('id');

        if ($moduleId === null) {
            return;
        }

        DB::table('role_module_permissions')->where('module_id', $moduleId)->delete();
        DB::table('modules')->where('id', $moduleId)->delete();
    }
};
