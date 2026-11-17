<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planering: tasks in shifts (ADR-037, gap 4). A template says which tasks
 * belong on which shifts (area, skill, weekdays, time of day); an instance is
 * the task on one shift, copied from a template or written for that shift,
 * ticked off with a time and the user who ticked it. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_task_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('instructions', 500)->nullable();
            $table->foreignId('area_id')->nullable()->constrained('planning_areas')->nullOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained('planning_skills')->nullOnDelete();
            // ISO weekdays, 1 = Monday … 7 = Sunday.
            $table->jsonb('weekdays');
            // Wall-clock time that must fall inside the shift; null means any shift.
            $table->string('due_time', 5)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('planning_shift_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained('planning_shifts')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('planning_task_templates')->nullOnDelete();
            $table->string('title', 120);
            $table->string('instructions', 500)->nullable();
            $table->string('due_time', 5)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampTz('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'shift_id']);
            $table->index(['company_id', 'done_at']);
        });

        // A template puts a task on a shift only once.
        DB::statement('CREATE UNIQUE INDEX planning_shift_tasks_one_per_template ON planning_shift_tasks (shift_id, template_id) WHERE template_id IS NOT NULL');
    }
};
