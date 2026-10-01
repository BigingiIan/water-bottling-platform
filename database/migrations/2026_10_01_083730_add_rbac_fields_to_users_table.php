<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $timestamp = now();
        $legacyRoles = [
            ['name' => 'Administrator', 'slug' => 'administrator'],
            ['name' => 'Production Manager', 'slug' => 'production-manager'],
            ['name' => 'Production Staff', 'slug' => 'production-staff'],
        ];

        foreach ($legacyRoles as $role) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $role['slug']],
                ['name' => $role['name'], 'updated_at' => $timestamp, 'created_at' => $timestamp],
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('status', ['active', 'pending', 'suspended'])->default('active');
            $table->timestamp('last_active_at')->nullable();
        });

        $legacyRoles = [
            'admin' => 'administrator',
            'manager' => 'production-manager',
            'operator' => 'production-staff',
        ];

        foreach ($legacyRoles as $legacyRole => $roleSlug) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');

            DB::table('users')->where('role', $legacyRole)->update(['role_id' => $roleId]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable(false)->change();
            $table->dropColumn('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'manager', 'operator'])->default('operator');
        });

        $roles = DB::table('roles')->pluck('slug', 'id');
        $legacyRoles = [
            'administrator' => 'admin',
            'production-manager' => 'manager',
        ];

        foreach ($roles as $roleId => $roleSlug) {
            DB::table('users')->where('role_id', $roleId)->update([
                'role' => $legacyRoles[$roleSlug] ?? 'operator',
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['status', 'last_active_at']);
        });
    }
};
