<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->foreignId('target_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->text('old_values')->nullable()->after('table_name');
            $table->text('new_values')->nullable()->after('old_values');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_user_id');
            $table->dropColumn(['old_values', 'new_values']);
        });
    }
};
