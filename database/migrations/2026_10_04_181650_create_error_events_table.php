<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('status_code');
            $table->string('module_slug')->nullable();
            $table->string('url', 2048);
            $table->string('method', 10);
            $table->string('exception_class')->nullable();
            $table->string('message', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['status_code', 'created_at']);
            $table->index(['module_slug', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
