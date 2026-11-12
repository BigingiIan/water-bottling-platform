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
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->enum('type', ['filler', 'capper', 'labeller', 'conveyor', 'ro_unit', 'uv_steriliser', 'ozone_generator', 'storage_tank', 'compressor', 'generator', 'other']);
            $table->foreignId('production_line_id')->nullable()->constrained('production_lines')->nullOnDelete();
            $table->string('location', 120)->nullable();
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->date('installed_on')->nullable();
            $table->enum('status', ['operational', 'needs_attention', 'under_maintenance', 'out_of_service'])->default('operational');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['production_line_id', 'status']);
        });

        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('task', 160);
            $table->unsignedSmallInteger('every_days');
            // The first due date while the task has never been done; afterwards next_due_on follows last_done_on.
            $table->date('first_due_on');
            $table->date('last_done_on')->nullable();
            $table->date('next_due_on');
            $table->foreignId('assigned_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->json('checklist')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'next_due_on']);
        });

        Schema::create('work_order_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->foreignId('maintenance_plan_id')->nullable()->constrained('maintenance_plans')->nullOnDelete();
            // The plan due date this work order was generated for; with the plan id it prevents duplicates.
            $table->date('due_on')->nullable();
            $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->enum('type', ['preventive', 'corrective', 'inspection']);
            $table->enum('source', ['plan', 'request', 'breakdown']);
            $table->enum('priority', ['low', 'normal', 'urgent'])->default('normal');
            $table->enum('status', ['open', 'in_progress', 'done', 'cancelled'])->default('open');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('downtime_minutes')->nullable();
            $table->text('parts_used')->nullable();
            $table->decimal('cost_kes', 12, 2)->nullable();
            $table->json('checklist_results')->nullable();
            $table->text('completion_notes')->nullable();
            $table->timestamps();

            $table->unique(['maintenance_plan_id', 'due_on']);
            $table->unique('request_id');
            $table->index(['status', 'priority']);
            $table->index(['equipment_id', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('work_order_number_sequences');
        Schema::dropIfExists('maintenance_plans');
        Schema::dropIfExists('equipment');
    }
};
