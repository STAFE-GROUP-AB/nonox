<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets per account, cost centre and project (ADR-081 gap 2, E3). A budget
 * is a plan next to the books: nothing here is posted or part of the voucher
 * hash chain. A LOCKED version's lines cannot be changed, by the model and by
 * the trigger below (its own trigger, not on any voucher table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->string('name', 60);
            $table->string('kind', 20)->default('BUDGET');
            $table->string('status', 20)->default('DRAFT');
            $table->boolean('is_active')->default(false);
            $table->date('forecast_cutoff')->nullable();
            $table->foreignId('source_version_id')->nullable()->constrained('budget_versions')->nullOnDelete();
            $table->jsonb('method')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('locked_by')->nullable()->constrained('users');
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'fiscal_year_id']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('budget_version_id')->constrained('budget_versions')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
            $table->foreignId('project_id')->nullable()->constrained('projects');
            $table->date('period');
            // Signed as in the ledger and in SIE #PBUDGET: debit positive, credit negative.
            $table->bigInteger('amount_ore');
            $table->boolean('is_override')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'budget_version_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX budget_versions_one_active
                ON budget_versions (company_id, fiscal_year_id, kind)
                WHERE is_active;

            CREATE UNIQUE INDEX budget_lines_cell_unique
                ON budget_lines (budget_version_id, account_id, COALESCE(cost_center_id, 0), COALESCE(project_id, 0), period);

            ALTER TABLE budget_versions ADD CONSTRAINT budget_versions_kind_check CHECK (kind IN ('BUDGET', 'FORECAST'));
            ALTER TABLE budget_versions ADD CONSTRAINT budget_versions_status_check CHECK (status IN ('DRAFT', 'LOCKED'));
            ALTER TABLE budget_lines ADD CONSTRAINT budget_lines_period_first_of_month CHECK (EXTRACT(DAY FROM period) = 1);

            -- A locked version is read-only: its lines are neither added, changed nor removed,
            -- except when the whole version is deleted (the cascade runs after the version row is gone).
            CREATE OR REPLACE FUNCTION budget_lines_locked_version() RETURNS trigger AS $$
            DECLARE
                version_status text;
            BEGIN
                SELECT status INTO version_status FROM budget_versions
                    WHERE id = CASE WHEN TG_OP = 'INSERT' THEN NEW.budget_version_id ELSE OLD.budget_version_id END;

                IF version_status = 'LOCKED' THEN
                    RAISE EXCEPTION 'The lines of a locked budget version cannot be changed.';
                END IF;

                IF TG_OP = 'UPDATE' AND NEW.budget_version_id <> OLD.budget_version_id THEN
                    RAISE EXCEPTION 'A budget line cannot be moved to another version.';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER budget_lines_locked_version
                BEFORE INSERT OR UPDATE OR DELETE ON budget_lines
                FOR EACH ROW EXECUTE FUNCTION budget_lines_locked_version();

            -- A locked version stays locked: only which version is active may change (and
            -- source_version_id, which ON DELETE SET NULL clears when the source is deleted).
            CREATE OR REPLACE FUNCTION budget_versions_stay_locked() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'LOCKED' AND (
                    NEW.status <> 'LOCKED'
                    OR NEW.company_id <> OLD.company_id
                    OR NEW.created_by <> OLD.created_by
                    OR NEW.locked_by IS DISTINCT FROM OLD.locked_by
                    OR NEW.locked_at IS DISTINCT FROM OLD.locked_at
                    OR NEW.fiscal_year_id <> OLD.fiscal_year_id
                    OR NEW.kind <> OLD.kind
                    OR NEW.name <> OLD.name
                    OR NEW.forecast_cutoff IS DISTINCT FROM OLD.forecast_cutoff
                    OR NEW.method IS DISTINCT FROM OLD.method
                ) THEN
                    RAISE EXCEPTION 'A locked budget version cannot be changed.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER budget_versions_stay_locked
                BEFORE UPDATE ON budget_versions
                FOR EACH ROW EXECUTE FUNCTION budget_versions_stay_locked();
        SQL);
    }
};
