<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The voucher that books a run's withheld tax and contributions against the
 * tax account (skattekontot). It is set once, after the run is posted, so the
 * protection of approved runs lets this column change too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->foreignUuid('tax_voucher_id')->nullable()->constrained('vouchers');
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
                   AND (to_jsonb(NEW) - 'status' - 'posted_voucher_id' - 'agi_submission_id' - 'tax_voucher_id' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'posted_voucher_id' - 'agi_submission_id' - 'tax_voucher_id' - 'updated_at') THEN
                    RAISE EXCEPTION 'approved payroll run % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.tax_voucher_id IS NOT NULL AND NEW.tax_voucher_id IS DISTINCT FROM OLD.tax_voucher_id THEN
                    RAISE EXCEPTION 'the tax payment of payroll run % is already booked', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.status NOT IN ('DRAFT', 'CALCULATED') AND NEW.status IN ('DRAFT', 'CALCULATED') THEN
                    RAISE EXCEPTION 'approved payroll run % cannot be reopened', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);
    }
};
