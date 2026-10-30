<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each employee's semesterår when it is closed (semesterlagen 18, 26, 26 a §§):
 * days saved for later years, unused days paid out, saved days used during the
 * year, and vacation pay on variable pay. The payout is paid in the next
 * payroll run; only the run it was paid in is set afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacation_year_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('year_start');
            $table->date('year_end');
            $table->unsignedSmallInteger('days_earned');
            $table->unsignedSmallInteger('days_taken');
            $table->unsignedSmallInteger('saved_days_used');
            $table->unsignedSmallInteger('days_saved');
            $table->unsignedSmallInteger('days_paid_out');
            $table->bigInteger('payout_ore');
            $table->bigInteger('variable_basis_ore');
            $table->bigInteger('variable_pay_ore');
            $table->foreignId('closed_by')->constrained('users');
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs');
            $table->timestamps();

            $table->unique(['employee_id', 'year_start']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vacation_year_closings_protect() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'a closed vacation year cannot be deleted'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF (to_jsonb(NEW) - 'payroll_run_id' - 'updated_at') <> (to_jsonb(OLD) - 'payroll_run_id' - 'updated_at') THEN
                    RAISE EXCEPTION 'a closed vacation year cannot be changed'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER vacation_year_closings_protect
                BEFORE UPDATE OR DELETE ON vacation_year_closings
                FOR EACH ROW EXECUTE FUNCTION vacation_year_closings_protect();
            SQL);
    }
};
