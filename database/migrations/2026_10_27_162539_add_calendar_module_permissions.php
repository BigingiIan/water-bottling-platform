<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds the Business Calendar module: administrators and production or sales managers get full
     * access, every other role view.
     */
    public function up(): void
    {
        $moduleId = DB::table('modules')->where('slug', 'calendar')->value('id')
            ?? DB::table('modules')->insertGetId([
                'name' => 'Business Calendar',
                'slug' => 'calendar',
                'description' => 'Public holidays, school terms and promotions the forecast takes into account',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach (DB::table('roles')->get(['id', 'slug']) as $role) {
            DB::table('role_module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                ['access_level' => self::managesCalendar($role->slug) ? 'full' : 'view'],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $moduleId = DB::table('modules')->where('slug', 'calendar')->value('id');

        if ($moduleId !== null) {
            DB::table('role_module_permissions')->where('module_id', $moduleId)->delete();
            DB::table('modules')->where('id', $moduleId)->delete();
        }
    }

    private static function managesCalendar(string $slug): bool
    {
        return $slug === 'administrator'
            || $slug === 'production-manager'
            || (str_contains($slug, 'sales') && str_contains($slug, 'manager'));
    }
};
