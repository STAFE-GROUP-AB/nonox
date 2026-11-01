<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll files (löneunderlag) from scheduling and time systems, PAXml or
 * CSV: the source systems with their mapping of external pay codes to the
 * company's own, each file read into a payroll run, and its lines waiting
 * for a person to approve them as inputs to the run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_import_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('payroll_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('payroll_import_source_id')->constrained('payroll_import_sources')->cascadeOnDelete();
            $table->string('external_code', 40);
            $table->string('description', 120)->nullable();
            $table->foreignId('pay_code_id')->nullable()->constrained('pay_codes')->nullOnDelete();
            $table->boolean('ignored')->default(false);
            $table->timestamps();
            $table->unique(['payroll_import_source_id', 'external_code']);
        });

        Schema::create('payroll_file_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs');
            $table->foreignId('payroll_import_source_id')->constrained('payroll_import_sources');
            $table->char('period', 7);
            $table->string('format', 10);
            $table->string('format_version', 10)->nullable();
            $table->string('original_name');
            $table->string('file_path');
            $table->char('sha256', 64);
            $table->unsignedInteger('size');
            $table->jsonb('report')->default('{}');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // The same file is read once per period.
            $table->unique(['company_id', 'period', 'sha256']);
            $table->index(['company_id', 'payroll_run_id']);
        });

        Schema::create('payroll_file_import_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('payroll_file_import_id')->constrained('payroll_file_imports')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('section', 12);
            $table->string('employee_ref', 40)->nullable();
            // Only ÅÅÅÅMMDD-XXXX, for an employee the file names but NONOX does not know.
            $table->string('person_ref', 13)->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('external_code', 40)->nullable();
            $table->text('description')->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->bigInteger('rate_ore')->nullable();
            $table->bigInteger('amount_ore')->nullable();
            $table->foreignId('pay_code_id')->nullable()->constrained('pay_codes')->nullOnDelete();
            $table->string('status', 12);
            $table->jsonb('problems')->default('[]');
            $table->jsonb('warnings')->default('[]');
            $table->foreignId('payroll_input_id')->nullable()->constrained('payroll_inputs')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['payroll_file_import_id', 'line_no']);
        });

        DB::statement("ALTER TABLE payroll_file_import_lines ADD CONSTRAINT payroll_file_import_lines_status CHECK (status IN ('PROPOSED', 'APPROVED', 'REJECTED', 'SKIPPED'))");
        DB::statement("ALTER TABLE payroll_file_imports ADD CONSTRAINT payroll_file_imports_format CHECK (format IN ('PAXML', 'CSV'))");
    }
};
