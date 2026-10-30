<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An approved payroll run never changes (PAYROLL_MODULE.md B.1, B.10): its
 * payslips, lines and inputs are frozen and the run only moves forward in
 * status. Corrections go in a later run. Each payslip keeps the
 * year-to-date changes it brings, applied when the run is approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->jsonb('accumulator_deltas')->default('{}');
            $table->jsonb('tax_explanation')->default('{}');
            $table->jsonb('contribution_explanation')->default('{}');
            $table->jsonb('warnings')->default('[]');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_runs_protect_approved() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status NOT IN ('DRAFT', 'CALCULATED') THEN
                        RAISE EXCEPTION 'approved payroll run % cannot be deleted', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status NOT IN ('DRAFT', 'CALCULATED')
                   AND (to_jsonb(NEW) - 'status' - 'posted_voucher_id' - 'agi_submission_id' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'posted_voucher_id' - 'agi_submission_id' - 'updated_at') THEN
                    RAISE EXCEPTION 'approved payroll run % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.status NOT IN ('DRAFT', 'CALCULATED') AND NEW.status IN ('DRAFT', 'CALCULATED') THEN
                    RAISE EXCEPTION 'approved payroll run % cannot be reopened', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payroll_runs_protect_approved
                BEFORE UPDATE OR DELETE ON payroll_runs
                FOR EACH ROW EXECUTE FUNCTION payroll_runs_protect_approved();

            CREATE OR REPLACE FUNCTION payroll_children_protect_approved() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                run_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'payslip_lines' THEN
                    SELECT payroll_run_id INTO run_id FROM payslips WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.payslip_id ELSE NEW.payslip_id END;
                ELSE
                    run_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.payroll_run_id ELSE NEW.payroll_run_id END;
                END IF;

                IF EXISTS (SELECT 1 FROM payroll_runs WHERE id = run_id AND status NOT IN ('DRAFT', 'CALCULATED')) THEN
                    IF TG_TABLE_NAME = 'payslips' AND TG_OP = 'UPDATE'
                       AND (to_jsonb(NEW) - 'pdf_path' - 'published_at' - 'hash' - 'updated_at') = (to_jsonb(OLD) - 'pdf_path' - 'published_at' - 'hash' - 'updated_at') THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION '% of an approved payroll run cannot be changed', TG_TABLE_NAME
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;

            CREATE TRIGGER payslips_protect_approved BEFORE INSERT OR UPDATE OR DELETE ON payslips
                FOR EACH ROW EXECUTE FUNCTION payroll_children_protect_approved();
            CREATE TRIGGER payslip_lines_protect_approved BEFORE INSERT OR UPDATE OR DELETE ON payslip_lines
                FOR EACH ROW EXECUTE FUNCTION payroll_children_protect_approved();
            CREATE TRIGGER payroll_inputs_protect_approved BEFORE INSERT OR UPDATE OR DELETE ON payroll_inputs
                FOR EACH ROW EXECUTE FUNCTION payroll_children_protect_approved();
            SQL);
    }
};
