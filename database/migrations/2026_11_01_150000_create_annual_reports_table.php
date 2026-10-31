<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The texts of a K2 annual report (årsredovisning) per fiscal year: the
 * directors' report, the proposed dividend, the average number of employees
 * and the signatures. The figures come from the books when it is rendered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annual_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->text('business_description')->nullable();
            $table->text('significant_events')->nullable();
            $table->bigInteger('dividend_ore')->default(0);
            $table->integer('average_employees')->default(0);
            $table->string('signing_place')->nullable();
            $table->date('signing_date')->nullable();
            $table->jsonb('signatories');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();

            $table->unique('fiscal_year_id');
        });
    }
};
