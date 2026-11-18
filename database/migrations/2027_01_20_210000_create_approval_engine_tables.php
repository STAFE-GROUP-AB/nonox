<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The approval engine (ADR-081 gap 8, E2): approval policies with steps per
 * company and subject type, one approval request per subject with a frozen
 * copy of the policy it follows, the decisions on it (append-only) and
 * substitutes for a date range. A company without a policy of its own keeps
 * today's single attest, which needs none of these rows.
 *
 * Cost centres and projects gain an owner, who can be named as approver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->string('name', 120);
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'subject_type', 'is_active', 'priority']);
            $table->unique(['id', 'company_id']);
        });

        Schema::create('approval_policy_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('approval_policy_id');
            $table->unsignedSmallInteger('position');
            $table->jsonb('conditions');
            $table->string('approver_kind', 20);
            $table->string('approver_permission', 60)->nullable();
            $table->jsonb('approver_user_ids')->nullable();
            // COST_CENTER or PROJECT for approver_kind DIMENSION_OWNER.
            $table->string('approver_dimension', 20)->nullable();
            $table->unsignedSmallInteger('required_count')->default(1);
            $table->boolean('exclude_preparer')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'approval_policy_id']);
            $table->unique(['approval_policy_id', 'position']);
            // The policy must belong to the same company: the foreign key includes company_id.
            $table->foreign(['approval_policy_id', 'company_id'])->references(['id', 'company_id'])->on('approval_policies')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE approval_policy_steps ADD CONSTRAINT approval_policy_steps_count_positive CHECK (required_count BETWEEN 1 AND 20)');

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->string('subject_id', 64);
            $table->unsignedBigInteger('approval_policy_id')->nullable();
            $table->jsonb('policy_snapshot');
            $table->string('status', 20)->default('PENDING');
            $table->unsignedSmallInteger('current_step')->default(1);
            $table->foreignId('prepared_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'status']);
            $table->unique(['id', 'company_id']);
        });

        // At most one open request per subject.
        DB::statement("CREATE UNIQUE INDEX approval_requests_one_pending ON approval_requests (company_id, subject_type, subject_id) WHERE status = 'PENDING'");
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_valid CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED', 'WITHDRAWN'))");

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('approval_request_id');
            $table->unsignedSmallInteger('step');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('on_behalf_of')->nullable()->constrained('users');
            $table->string('decision', 10);
            $table->string('reason', 500)->nullable();
            $table->timestamp('decided_at');

            $table->index(['company_id', 'approval_request_id']);
            $table->foreign(['approval_request_id', 'company_id'])->references(['id', 'company_id'])->on('approval_requests');
        });

        DB::statement("ALTER TABLE approval_decisions ADD CONSTRAINT approval_decisions_decision_valid CHECK (decision IN ('APPROVE', 'REJECT'))");

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('substitute_user_id')->constrained('users');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'substitute_user_id', 'starts_on']);
            $table->index(['company_id', 'user_id']);
        });

        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_dates CHECK (starts_on <= ends_on)');
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_not_self CHECK (user_id <> substitute_user_id)');

        foreach (['cost_centers', 'projects'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION approval_decisions_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Approval decisions cannot be changed or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER approval_decisions_append_only
                BEFORE UPDATE OR DELETE ON approval_decisions
                FOR EACH ROW EXECUTE FUNCTION approval_decisions_append_only();

            CREATE OR REPLACE FUNCTION approval_requests_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Approval requests cannot be deleted.';
                END IF;

                IF OLD.status <> 'PENDING' THEN
                    RAISE EXCEPTION 'A completed approval request cannot be changed.';
                END IF;

                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.subject_type IS DISTINCT FROM OLD.subject_type
                    OR NEW.subject_id IS DISTINCT FROM OLD.subject_id
                    OR NEW.policy_snapshot IS DISTINCT FROM OLD.policy_snapshot
                    OR NEW.prepared_by IS DISTINCT FROM OLD.prepared_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'The subject and policy of an approval request cannot be changed.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER approval_requests_guard
                BEFORE UPDATE OR DELETE ON approval_requests
                FOR EACH ROW EXECUTE FUNCTION approval_requests_guard();
        SQL);
    }
};
