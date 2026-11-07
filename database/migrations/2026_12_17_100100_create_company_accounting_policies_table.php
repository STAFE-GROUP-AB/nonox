<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * K3 accounting policies as data (ADR-059, addendum 2026-11-07, A.2.3): one row
 * per company, policy key and fiscal-year start from which it applies. The
 * policy in force for a year is the row with the latest applies_from on or
 * before the year's start. Keys and values are backed enums in
 * App\Domain\AnnualReport (K3PolicyKey and its value enums).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_accounting_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('value', 60);
            // Always the start date of one of the company's fiscal years.
            $table->date('applies_from');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'key', 'applies_from']);
        });
    }
};
