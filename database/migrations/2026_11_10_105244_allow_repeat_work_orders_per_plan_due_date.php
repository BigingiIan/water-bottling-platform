<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plan can need a second work order for the same due date: after the first was cancelled, or when a
     * short-cycle task is completed early and falls due again on that date. The generator now guards on
     * "the plan has an open work order" instead of this unique key.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            // Added first: the foreign key on maintenance_plan_id needs an index at every moment.
            $table->index(['maintenance_plan_id', 'status'], 'work_orders_plan_status_index');
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropUnique(['maintenance_plan_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->unique(['maintenance_plan_id', 'due_on']);
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropIndex('work_orders_plan_status_index');
        });
    }
};
