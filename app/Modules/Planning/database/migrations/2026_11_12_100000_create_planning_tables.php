<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planering (PLANNING_MODULE.md §4, steps 1–2): areas and skills, shifts,
 * templates and publications. A shift's times are timestamptz in UTC, shown
 * in Europe/Stockholm; an open shift has no employee. The schedule never
 * writes to the staff ledger. Availability, requests, attestations and iCal
 * tokens come with steps 3–4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('colour', 7);
            $table->foreignId('location_id')->nullable()->constrained('staff_ledger_locations')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'archived_at']);
        });

        Schema::create('planning_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('planning_employee_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('planning_skills')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'skill_id']);
            $table->index(['company_id', 'skill_id']);
        });

        Schema::create('planning_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('weeks')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });

        Schema::create('planning_template_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('planning_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('week_index');
            $table->unsignedSmallInteger('weekday');
            $table->string('start', 5);
            $table->string('end', 5);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('planning_areas')->nullOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained('planning_skills')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'template_id']);
        });

        Schema::create('planning_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('planning_areas')->nullOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained('planning_skills')->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->string('note', 500)->nullable();
            $table->string('status', 12)->default('DRAFT');
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            // What the employee was last shown, kept while a published shift has unpublished changes.
            $table->jsonb('published_snapshot')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 12)->default('MANUAL');
            $table->foreignId('template_id')->nullable()->constrained('planning_templates')->nullOnDelete();
            $table->bigInteger('cost_estimate_ore')->nullable();
            $table->jsonb('cost_details')->nullable();
            $table->timestampTz('cost_calculated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'starts_at']);
            $table->index(['company_id', 'employee_id', 'starts_at']);
        });

        DB::statement('ALTER TABLE planning_shifts ADD CONSTRAINT planning_shifts_times CHECK (ends_at > starts_at)');
        DB::statement("ALTER TABLE planning_shifts ADD CONSTRAINT planning_shifts_status CHECK (status IN ('DRAFT', 'PUBLISHED', 'CANCELLED'))");

        Schema::create('planning_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('from');
            $table->date('to');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at');
            $table->unsignedInteger('shift_count')->default(0);
            $table->unsignedInteger('notified_count')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'from']);
        });
    }
};
