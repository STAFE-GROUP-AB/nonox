<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schedule proposals, v1 (ADR-037 amendment, gap 3): one row per run (the
 * period, the parameters, counts and the engine version) and one item per
 * open shift with the proposed employee or none, the score parts and the
 * reasons as codes. The score parts are personal data about how work is
 * shared out; proposals are deleted after 90 days (planning:prune-proposals).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->jsonb('parameters');
            $table->string('engine_version', 16);
            $table->unsignedInteger('slot_count')->default(0);
            $table->unsignedInteger('filled_count')->default(0);
            $table->unsignedInteger('swaps_kept')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });

        DB::statement('ALTER TABLE planning_proposals ADD CONSTRAINT planning_proposals_period CHECK (period_to >= period_from)');

        Schema::create('planning_proposal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('proposal_id')->constrained('planning_proposals')->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained('planning_shifts')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->unsignedInteger('position');
            $table->jsonb('score_parts')->nullable();
            $table->jsonb('reasons');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'proposal_id']);
        });
    }
};
