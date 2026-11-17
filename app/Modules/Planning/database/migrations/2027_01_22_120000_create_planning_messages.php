<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planering: messages to staff tied to the schedule (ADR-037, gap 5). A
 * message is addressed by a scope (a shift, a day, a period, an area in a
 * period, or everyone employed in a period); its recipients are frozen when
 * it is sent, one row each, with when they were notified, read it and
 * acknowledged it. The text is encrypted at rest. Pruned 12 months after the
 * last day the message concerns. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 8);
            $table->foreignUuid('shift_id')->nullable()->constrained('planning_shifts')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('planning_areas')->nullOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            // The encrypted cast stores ciphertext; the 2 000 character limit is checked before encryption.
            $table->text('body');
            $table->boolean('requires_ack')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'date_to']);
            // planning:prune-messages deletes across every company by date_to.
            $table->index('date_to');
        });

        Schema::create('planning_message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('planning_messages')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestampTz('notified_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'employee_id']);
            $table->index(['company_id', 'employee_id']);
        });
    }
};
