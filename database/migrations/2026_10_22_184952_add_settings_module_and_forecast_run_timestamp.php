<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_records', function (Blueprint $table): void {
            $table->timestamp('generated_at')->nullable()->index();
        });

        $settingsModule = DB::table('modules')->where('slug', 'settings')->first();
        $auditModule = DB::table('modules')->where('slug', 'audit-logs')->first();

        if ($settingsModule === null && $auditModule !== null) {
            DB::table('modules')->where('id', $auditModule->id)->update([
                'name' => 'Settings',
                'slug' => 'settings',
                'description' => 'Application settings and audit trail',
                'updated_at' => now(),
            ]);
            $settingsModuleId = $auditModule->id;
        } elseif ($settingsModule !== null) {
            $settingsModuleId = $settingsModule->id;

            if ($auditModule !== null) {
                DB::table('role_module_permissions')->where('module_id', $auditModule->id)->delete();
                DB::table('modules')->where('id', $auditModule->id)->delete();
            }
        } else {
            $settingsModuleId = DB::table('modules')->insertGetId([
                'name' => 'Settings',
                'slug' => 'settings',
                'description' => 'Application settings and audit trail',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('roles')->get(['id', 'slug']) as $role) {
            DB::table('role_module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $settingsModuleId],
                ['access_level' => $role->slug === 'administrator' ? 'full' : 'none'],
            );
        }
    }

    public function down(): void
    {
        $settingsModuleId = DB::table('modules')->where('slug', 'settings')->value('id');

        if ($settingsModuleId !== null) {
            DB::table('role_module_permissions')->where('module_id', $settingsModuleId)->delete();
            DB::table('modules')->where('id', $settingsModuleId)->delete();
        }

        Schema::table('forecast_records', function (Blueprint $table): void {
            $table->dropColumn('generated_at');
        });
    }
};
