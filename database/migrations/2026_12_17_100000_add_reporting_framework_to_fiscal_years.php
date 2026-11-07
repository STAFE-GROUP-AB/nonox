<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-059, addendum 2026-11-07, A.2.1 (decision S1-A): the K-regelverk is a
 * property of the fiscal year. Every existing year gets its company's
 * framework (one UPDATE … FROM teams); then the column is required. No
 * voucher is touched. teams.reporting_framework stays as "the framework of
 * the latest year" and the default for the next one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table): void {
            $table->string('reporting_framework', 2)->nullable();
            $table->timestampTz('reporting_framework_changed_at')->nullable();
            $table->foreignId('reporting_framework_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reporting_framework_change_reason')->nullable();
        });

        DB::statement('UPDATE fiscal_years SET reporting_framework = teams.reporting_framework FROM teams WHERE teams.id = fiscal_years.company_id');
        DB::statement('ALTER TABLE fiscal_years ALTER COLUMN reporting_framework SET NOT NULL');
        DB::statement("ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_reporting_framework_check CHECK (reporting_framework IN ('K1', 'K2', 'K3'))");
    }
};
