<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a payslip did in each sjuklöneperiod it touched (sjuklönelagen 6–7 §§,
 * prop. 2017/18:96 s. 68–69): the karensavdrag the period is due, the part
 * deducted on this payslip (the rest is carried to later sick-pay days in
 * the same period, also in a later run and after reinfection), and the days
 * paid under a collective agreement's deduction from day 15, which count
 * towards the agreement's ceiling over twelve months. Written by the
 * calculation together with the payslip and frozen with it when the run is
 * approved (the same trigger as payslips, payslip_lines and payroll_inputs).
 *
 * The employment gets two facts for the agreement's sick pay from day 15:
 * a direct transfer from an employment with sick pay to day 90 (the route
 * into the 90-day group besides a year's employment) and the fixed monthly
 * supplements that count in "månadslön".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sick_pay_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs');
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('period_start');
            $table->date('last_day');
            $table->unsignedInteger('day_count');
            $table->bigInteger('karens_full_ore')->default(0);
            $table->bigInteger('karens_deducted_ore')->default(0);
            $table->jsonb('long_sick_dates')->default('[]');
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'period_start']);
            $table->index('payroll_run_id');
        });

        DB::statement('ALTER TABLE sick_pay_periods ADD CONSTRAINT sick_pay_periods_karens_check CHECK (karens_full_ore >= 0 AND karens_deducted_ore >= 0 AND karens_deducted_ore <= karens_full_ore)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER sick_pay_periods_protect_approved BEFORE INSERT OR UPDATE OR DELETE ON sick_pay_periods
                FOR EACH ROW EXECUTE FUNCTION payroll_children_protect_approved();
            SQL);

        Schema::table('employments', function (Blueprint $table) {
            $table->boolean('sick_pay_direct_transfer')->default(false);
            $table->bigInteger('fixed_supplements_ore')->nullable();
        });
    }
};
