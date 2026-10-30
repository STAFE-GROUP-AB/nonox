<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payroll data model (PAYROLL_MODULE.md B.4). The tables exist for every
 * company whether or not payroll is on (A.2). Personal numbers, bank details,
 * emergency contacts and notes are encrypted at rest (text columns), B.15.
 * Rule versions, tax tables and AGI submissions come with P-3, P-4 and P-11.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name');
            // Hours per weekday, possibly over rotating weeks: {"weeks": [[8,8,8,8,8,0,0]]}.
            $table->jsonb('pattern');
            $table->timestamps();
        });

        Schema::create('collective_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name');
            // Configurable rules only; no agreement text is copied (B.1, B.8).
            $table->jsonb('rules')->default('{}');
            $table->timestamps();
        });

        Schema::create('pension_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name');
            $table->string('template', 20);
            $table->jsonb('rules')->default('{}');
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('employee_number');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('first_name');
            $table->string('last_name');
            $table->text('personal_number');
            $table->date('birth_date');
            $table->string('address')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->char('country', 2)->default('SE');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->char('language', 2)->default('sv');
            $table->text('bank_clearing')->nullable();
            $table->text('bank_account')->nullable();
            $table->text('iban')->nullable();
            $table->text('bic')->nullable();
            $table->enum('tax_residence', ['SE', 'NON_RESIDENT_SINK', 'NON_RESIDENT_A_SINK'])->default('SE');
            $table->unsignedSmallInteger('tax_table')->nullable();
            $table->unsignedTinyInteger('tax_column')->nullable();
            $table->string('municipality_code', 4)->nullable();
            $table->boolean('church_member')->nullable();
            $table->enum('tax_adjustment_type', ['NONE', 'PERCENT', 'TABLE', 'AMOUNT'])->default('NONE');
            $table->bigInteger('tax_adjustment_value')->nullable();
            $table->date('tax_adjustment_valid_from')->nullable();
            $table->date('tax_adjustment_valid_to')->nullable();
            $table->text('emergency_contact')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['ACTIVE', 'ON_LEAVE', 'TERMINATED'])->default('ACTIVE');
            $table->timestamps();

            $table->unique(['company_id', 'employee_number']);
            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('employments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('employment_type', ['TILLSVIDARE', 'ALLMAN_VISSTID', 'VIKARIAT', 'SASONG', 'PROVANSTALLNING', 'TIMANSTALLD']);
            $table->enum('salary_form', ['MONTHLY', 'HOURLY']);
            $table->bigInteger('monthly_salary_ore')->nullable();
            $table->bigInteger('hourly_rate_ore')->nullable();
            $table->unsignedSmallInteger('employment_rate_bp')->default(10000);
            $table->decimal('weekly_hours', 5, 2)->nullable();
            $table->foreignId('work_schedule_id')->nullable()->constrained('work_schedules');
            $table->string('position_title')->nullable();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
            $table->foreignId('project_id')->nullable()->constrained('projects');
            $table->foreignId('collective_agreement_id')->nullable()->constrained('collective_agreements');
            $table->foreignId('pension_plan_id')->nullable()->constrained('pension_plans');
            $table->enum('vacation_rule', ['SAMMALONEREGELN', 'PROCENTREGELN'])->nullable();
            $table->unsignedTinyInteger('vacation_days_per_year')->nullable();
            $table->unsignedTinyInteger('payment_day')->nullable();
            $table->timestamps();
        });

        Schema::create('salary_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('employment_id')->constrained('employments');
            $table->date('valid_from');
            $table->bigInteger('monthly_salary_ore')->nullable();
            $table->bigInteger('hourly_rate_ore')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('pay_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name_sv');
            $table->string('name_en');
            $table->enum('category', ['SALARY', 'ADDITION', 'DEDUCTION', 'ABSENCE', 'BENEFIT', 'ALLOWANCE_TAX_FREE', 'ALLOWANCE_TAXABLE', 'EXPENSE', 'NET_DEDUCTION', 'NET_ADDITION', 'VACATION', 'SICK', 'PENSION', 'CORRECTION']);
            $table->enum('unit', ['AMOUNT', 'HOURS', 'DAYS', 'PERCENT', 'QUANTITY']);
            $table->string('formula_ref', 50)->nullable();
            $table->boolean('is_taxable');
            $table->enum('tax_method', ['TABLE', 'ONE_OFF', 'NONE']);
            $table->boolean('is_contribution_basis');
            $table->boolean('is_vacation_basis');
            $table->boolean('is_pension_basis');
            $table->string('agi_field_code', 10)->nullable();
            $table->foreignId('debit_account_id')->nullable()->constrained('accounts');
            $table->foreignId('credit_account_id')->nullable()->constrained('accounts');
            $table->boolean('use_employment_dimensions')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->char('period', 7);
            $table->date('payment_date');
            $table->enum('type', ['REGULAR', 'EXTRA', 'CORRECTION', 'FINAL_PAY'])->default('REGULAR');
            $table->enum('status', ['DRAFT', 'CALCULATED', 'APPROVED', 'PAID', 'POSTED', 'REPORTED'])->default('DRAFT');
            $table->foreignId('calculated_by')->nullable()->constrained('users');
            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('posted_voucher_id')->nullable()->constrained('vouchers');
            $table->unsignedBigInteger('agi_submission_id')->nullable();
            $table->char('rule_snapshot_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'period']);
        });

        Schema::create('payroll_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs');
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('pay_code_id')->constrained('pay_codes');
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->bigInteger('rate_ore')->nullable();
            $table->bigInteger('amount_ore')->nullable();
            $table->string('note')->nullable();
            $table->enum('source', ['MANUAL', 'TIME', 'ABSENCE', 'EXPENSE', 'IMPORT', 'RECURRING', 'RETRO'])->default('MANUAL');
            $table->timestamps();
        });

        Schema::create('recurring_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('pay_code_id')->constrained('pay_codes');
            $table->bigInteger('amount_ore')->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs');
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('employment_id')->constrained('employments');
            $table->bigInteger('gross_ore')->default(0);
            $table->bigInteger('taxable_ore')->default(0);
            $table->bigInteger('tax_ore')->default(0);
            $table->bigInteger('net_ore')->default(0);
            $table->bigInteger('contribution_basis_ore')->default(0);
            $table->bigInteger('employer_contribution_ore')->default(0);
            $table->string('pdf_path')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->char('hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id', 'employment_id']);
        });

        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained('payslips');
            $table->foreignId('pay_code_id')->constrained('pay_codes');
            $table->string('description');
            $table->decimal('quantity', 12, 3)->nullable();
            $table->bigInteger('rate_ore')->nullable();
            $table->bigInteger('amount_ore');
            $table->string('tax_effect', 20);
            $table->unsignedBigInteger('rule_version_id')->nullable();
            $table->jsonb('explanation')->default('{}');
            $table->timestamps();
        });

        Schema::create('accumulators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->enum('scope', ['CALENDAR_YEAR', 'VACATION_YEAR']);
            $table->unsignedSmallInteger('year');
            $table->string('key', 50);
            $table->bigInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'scope', 'year', 'key']);
        });

        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->enum('type', ['SICK', 'VAB', 'PARENTAL', 'VACATION', 'LEAVE_UNPAID', 'LEAVE_PAID', 'STUDY', 'MILITARY', 'OTHER']);
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedSmallInteger('extent_bp')->default(10000);
            $table->decimal('hours', 6, 2)->nullable();
            $table->boolean('doctor_certificate_received')->default(false);
            $table->foreignId('related_absence_id')->nullable()->constrained('absences');
            $table->text('note')->nullable();
            $table->enum('status', ['REQUESTED', 'APPROVED', 'REJECTED'])->default('REQUESTED');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->enum('type', ['CAR', 'FUEL', 'MEAL', 'HOUSING', 'OTHER']);
            $table->jsonb('value_rule')->default('{}');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->jsonb('car_data')->nullable();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->enum('type', ['OUTLAY', 'PER_DIEM_DOMESTIC', 'PER_DIEM_FOREIGN', 'MILEAGE_OWN_CAR', 'MILEAGE_COMPANY_CAR']);
            $table->date('date');
            $table->bigInteger('amount_ore');
            $table->bigInteger('vat_ore')->default(0);
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->string('receipt_path')->nullable();
            $table->jsonb('trip_data')->nullable();
            $table->enum('status', ['SUBMITTED', 'APPROVED', 'REJECTED', 'PAID'])->default('SUBMITTED');
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs');
            $table->timestamps();
        });

        Schema::create('time_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('date');
            $table->decimal('hours', 6, 2);
            $table->foreignId('pay_code_id')->constrained('pay_codes');
            $table->foreignId('project_id')->nullable()->constrained('projects');
            $table->enum('status', ['SUBMITTED', 'APPROVED', 'REJECTED'])->default('SUBMITTED');
            $table->timestamps();
        });
    }
};
