<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * NONOX SaaS, the recurring-revenue ledger (ADR-080 §3).
 *
 * - saas_contract_versions: every known state of a contract from a date. The
 *   single input of the calculation; append-only (trigger).
 * - saas_months: one row per company and month. A CLOSED month cannot be
 *   changed or removed (trigger).
 * - saas_customer_months: per month and customer, the MRR, the lines behind it
 *   and the movement. Rows of a closed month cannot be added, changed or
 *   removed (trigger).
 * - saas_corrections: what became known after a month was closed, booked in
 *   the first open month; append-only (trigger).
 * - saas_estimates: months before the module was switched on, estimated from
 *   issued recurring invoices (O9); kept apart from the ledger.
 *
 * The triggers let rows go only with their company (the cascade from teams,
 * whose row is then already gone), as for invoice_documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_contract_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('source_type', 30);
            $table->string('source_id', 64);
            // No foreign key: the row never changes, so ON DELETE SET NULL could not run.
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->date('effective_from');
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('cause', 30);
            $table->string('status', 20);
            $table->date('starts_on')->nullable();
            $table->date('cancel_effective_on')->nullable();
            $table->string('excluded', 40)->nullable();
            $table->jsonb('lines');
            $table->jsonb('source_ref')->nullable();
            $table->char('sha256', 64);
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'source_type', 'source_id', 'id']);
            $table->index(['company_id', 'customer_id']);
        });

        Schema::create('saas_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('month');
            $table->string('status', 10)->default('OPEN');
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->unsignedBigInteger('versions_known_until')->nullable();
            $table->jsonb('rates')->nullable();
            $table->bigInteger('opening_mrr_ore')->default(0);
            $table->unsignedInteger('opening_customers')->default(0);
            $table->bigInteger('mrr_ore')->default(0);
            $table->unsignedInteger('customers')->default(0);
            $table->bigInteger('new_ore')->default(0);
            $table->bigInteger('reactivation_ore')->default(0);
            $table->bigInteger('resumed_ore')->default(0);
            $table->bigInteger('expansion_ore')->default(0);
            $table->bigInteger('contraction_ore')->default(0);
            $table->bigInteger('pause_ore')->default(0);
            $table->bigInteger('churn_ore')->default(0);
            $table->bigInteger('fx_ore')->default(0);
            $table->bigInteger('correction_ore')->default(0);
            $table->unsignedInteger('new_customers')->default(0);
            $table->unsignedInteger('churned_customers')->default(0);
            $table->bigInteger('churn_from_active_ore')->default(0);
            $table->bigInteger('nrr_base_ore')->nullable();
            $table->bigInteger('nrr_ore')->nullable();
            $table->bigInteger('grr_ore')->nullable();
            $table->bigInteger('scheduled_churn_ore')->default(0);
            $table->unsignedInteger('source_issues')->default(0);
            $table->jsonb('missing_rates')->nullable();
            $table->jsonb('cohorts')->nullable();
            $table->timestampTz('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'month']);
        });

        Schema::create('saas_customer_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('month');
            $table->unsignedBigInteger('customer_id');
            $table->bigInteger('opening_ore')->default(0);
            $table->bigInteger('correction_ore')->default(0);
            $table->bigInteger('fx_ore')->default(0);
            $table->string('movement', 20)->nullable();
            $table->bigInteger('movement_ore')->default(0);
            $table->bigInteger('reclass_ore')->default(0);
            $table->bigInteger('mrr_ore')->default(0);
            $table->string('state', 10);
            $table->date('cohort_month')->nullable();
            $table->bigInteger('scheduled_churn_ore')->default(0);
            $table->date('scheduled_churn_month')->nullable();
            $table->jsonb('lines');
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'month', 'customer_id']);
            $table->index(['company_id', 'customer_id', 'month']);
        });

        Schema::create('saas_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('booked_month');
            $table->date('affects_month');
            $table->unsignedBigInteger('customer_id');
            $table->bigInteger('amount_ore');
            // The last version known when the correction was written.
            $table->unsignedBigInteger('version_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'booked_month']);
            $table->index(['company_id', 'affects_month']);
        });

        Schema::create('saas_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('month');
            $table->unsignedBigInteger('customer_id');
            $table->bigInteger('mrr_ore');
            $table->jsonb('invoice_ids');
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'month', 'customer_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION saas_append_only() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION '% is append-only: % is not allowed', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER saas_contract_versions_append_only
                BEFORE UPDATE OR DELETE ON saas_contract_versions
                FOR EACH ROW EXECUTE FUNCTION saas_append_only();

            CREATE TRIGGER saas_corrections_append_only
                BEFORE UPDATE OR DELETE ON saas_corrections
                FOR EACH ROW EXECUTE FUNCTION saas_append_only();

            CREATE OR REPLACE FUNCTION saas_no_truncate() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                RAISE EXCEPTION '% cannot be truncated', TG_TABLE_NAME
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER saas_contract_versions_no_truncate
                BEFORE TRUNCATE ON saas_contract_versions
                FOR EACH STATEMENT EXECUTE FUNCTION saas_no_truncate();

            CREATE TRIGGER saas_corrections_no_truncate
                BEFORE TRUNCATE ON saas_corrections
                FOR EACH STATEMENT EXECUTE FUNCTION saas_no_truncate();

            CREATE TRIGGER saas_months_no_truncate
                BEFORE TRUNCATE ON saas_months
                FOR EACH STATEMENT EXECUTE FUNCTION saas_no_truncate();

            CREATE TRIGGER saas_customer_months_no_truncate
                BEFORE TRUNCATE ON saas_customer_months
                FOR EACH STATEMENT EXECUTE FUNCTION saas_no_truncate();

            -- A closed month is frozen. An open month may be closed (one update), never reopened.
            CREATE OR REPLACE FUNCTION saas_months_closed_locked() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                        RETURN OLD;
                    END IF;

                    IF OLD.status = 'CLOSED' THEN
                        RAISE EXCEPTION 'a closed month cannot be deleted'
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;

                    RETURN OLD;
                END IF;

                IF OLD.status = 'CLOSED' THEN
                    RAISE EXCEPTION 'a closed month cannot be changed'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF NEW.company_id <> OLD.company_id OR NEW.month <> OLD.month THEN
                    RAISE EXCEPTION 'a month cannot be moved'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF NEW.status = 'CLOSED' AND (NEW.closed_at IS NULL OR NEW.versions_known_until IS NULL) THEN
                    RAISE EXCEPTION 'a closed month needs closed_at and versions_known_until'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER saas_months_locked
                BEFORE UPDATE OR DELETE ON saas_months
                FOR EACH ROW EXECUTE FUNCTION saas_months_closed_locked();

            -- A customer's row of a closed month is frozen with its month.
            CREATE OR REPLACE FUNCTION saas_customer_months_locked() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                row_company bigint;
                row_month date;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                        RETURN OLD;
                    END IF;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    row_company := NEW.company_id;
                    row_month := NEW.month;
                ELSE
                    row_company := OLD.company_id;
                    row_month := OLD.month;
                END IF;

                IF EXISTS (SELECT 1 FROM saas_months WHERE company_id = row_company AND month = row_month AND status = 'CLOSED') THEN
                    RAISE EXCEPTION 'the month is closed: % is not allowed', TG_OP
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP = 'UPDATE' AND EXISTS (SELECT 1 FROM saas_months WHERE company_id = NEW.company_id AND month = NEW.month AND status = 'CLOSED') THEN
                    RAISE EXCEPTION 'a row cannot be moved into a closed month'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER saas_customer_months_locked
                BEFORE INSERT OR UPDATE OR DELETE ON saas_customer_months
                FOR EACH ROW EXECUTE FUNCTION saas_customer_months_locked();
            SQL);
    }
};
