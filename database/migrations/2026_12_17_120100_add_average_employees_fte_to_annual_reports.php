<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The average number of employees as full-time equivalents with two
 * decimals (ADR-059, addendum 2026-11-07, A.2.5). The size test keeps the
 * exact figure and rounds it to a whole number itself (BFN's vägledning
 * Gränsvärden, example 3), so 3,5 counts as 4; the K2 note keeps printing the
 * whole-number average_employees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_reports', function (Blueprint $table): void {
            $table->decimal('average_employees_fte', 8, 2)->nullable()->after('average_employees');
        });
    }
};
