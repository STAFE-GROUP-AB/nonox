<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approved time reports are paid by the payroll run for their month (GitHub
 * issue #15). The run that pays a report claims it, so it is never paid
 * twice; once that run is approved the report is frozen, like the run's
 * payslips and inputs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_reports', function (Blueprint $table) {
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs');
            $table->index(['company_id', 'date']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION time_reports_protect_paid() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM payroll_runs WHERE id = OLD.payroll_run_id AND status NOT IN ('DRAFT', 'CALCULATED')) THEN
                    RAISE EXCEPTION 'time report % is paid by an approved payroll run and cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP = 'UPDATE' AND NEW.payroll_run_id IS DISTINCT FROM OLD.payroll_run_id
                   AND EXISTS (SELECT 1 FROM payroll_runs WHERE id = NEW.payroll_run_id AND status NOT IN ('DRAFT', 'CALCULATED')) THEN
                    RAISE EXCEPTION 'time report % cannot be added to an approved payroll run', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;

            CREATE TRIGGER time_reports_protect_paid BEFORE UPDATE OR DELETE ON time_reports
                FOR EACH ROW EXECUTE FUNCTION time_reports_protect_paid();
            SQL);
    }
};
