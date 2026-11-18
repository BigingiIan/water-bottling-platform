<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Temporary passwords already outstanding get the standard 72 hour window from the moment of migration.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('temporary_password_expires_at')->nullable()->after('must_change_password');
        });

        DB::table('users')
            ->where('must_change_password', true)
            ->update(['temporary_password_expires_at' => now()->addHours(72)]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('temporary_password_expires_at');
        });
    }
};
