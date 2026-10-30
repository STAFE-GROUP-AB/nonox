<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving payroll from an earlier system (PAYROLL_MODULE.md B.16): the
 * migration's state and report, the vacation balances carried over per
 * semesterår, and the payslips from the earlier system kept as PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_migrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('teams')->cascadeOnDelete();
            $table->string('status', 20)->default('IN_PROGRESS');
            $table->unsignedSmallInteger('year');
            $table->jsonb('report');
            $table->foreignId('started_by')->constrained('users');
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vacation_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('year_start');
            $table->unsignedSmallInteger('days_earned');
            $table->unsignedSmallInteger('days_taken');
            $table->unsignedSmallInteger('days_saved');
            $table->bigInteger('basis_ore')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'year_start']);
        });

        Schema::create('imported_payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->char('period', 7);
            $table->string('file_path');
            $table->string('original_name');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();

            $table->unique(['employee_id', 'sha256']);
        });
    }
};
