<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks within a company, assigned to one of its members (the firm's
 * consultant or the client), with comments, a checklist and recurring
 * templates; the month-close check-offs and each user's saved views on
 * Mina företag (ADR-028).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->string('frequency', 10);
            $table->unsignedTinyInteger('due_day');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'active']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->string('status', 20)->default('TODO');
            $table->string('priority', 10)->default('NORMAL');
            $table->date('due_date')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->string('subject_id', 40)->nullable();
            $table->foreignId('recurrence_id')->nullable()->constrained('task_recurrences')->nullOnDelete();
            $table->char('period', 7)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'due_date']);
            $table->index(['assignee_id', 'status']);
            $table->unique(['recurrence_id', 'period']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->text('body');
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('label', 200);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['task_id', 'position']);
        });

        Schema::create('month_close_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->char('month', 7);
            $table->string('step', 20);
            $table->foreignId('checked_by')->constrained('users');
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->unique(['company_id', 'month', 'step']);
        });

        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('page', 40);
            $table->string('name', 60);
            $table->jsonb('filters');
            $table->timestamps();

            $table->unique(['user_id', 'page', 'name']);
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->jsonb('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('task_digest', 10)->default('NONE');
        });
    }
};
