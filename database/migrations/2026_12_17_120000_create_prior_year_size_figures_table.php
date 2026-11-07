<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The size figures of a fiscal year before the company's first year in
 * NONOX (ADR-059, addendum 2026-11-07, A.2.5), entered by a person so that
 * the size test (ÅRL 1 kap. 3 §, BFNAR 2006:11) has its two preceding years.
 * A year kept in NONOX is read from the ledger instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prior_year_size_figures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            // Full-time equivalents (BFNAR 2006:11 points 7–10); null while not known.
            $table->decimal('average_employees_fte', 8, 2)->nullable();
            $table->bigInteger('balance_sheet_total_ore');
            $table->bigInteger('net_turnover_ore');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'start_date']);
        });

        DB::statement('ALTER TABLE prior_year_size_figures ADD CONSTRAINT prior_year_size_figures_values_check CHECK (end_date > start_date AND balance_sheet_total_ore >= 0 AND (average_employees_fte IS NULL OR average_employees_fte >= 0))');
    }
};
