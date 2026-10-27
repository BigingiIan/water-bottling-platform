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
        Schema::create('request_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->enum('type', ['stock_check', 'production_request', 'leakage_review', 'data_correction', 'access_request', 'general']);
            $table->string('subject');
            $table->text('body');
            $table->enum('priority', ['low', 'normal', 'urgent'])->default('normal');
            $table->enum('status', ['open', 'acknowledged', 'in_progress', 'done', 'declined', 'cancelled'])->default('open');
            $table->foreignId('from_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('to_role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            // A short key from RequestContext::RELATED_TYPES, never a class name supplied by the client.
            $table->string('related_type', 30)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->json('context_snapshot')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();

            $table->index(['to_role_id', 'status']);
            $table->index(['from_user_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->index(['related_type', 'related_id']);
        });

        Schema::create('request_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['request_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_comments');
        Schema::dropIfExists('requests');
        Schema::dropIfExists('request_number_sequences');
    }
};
