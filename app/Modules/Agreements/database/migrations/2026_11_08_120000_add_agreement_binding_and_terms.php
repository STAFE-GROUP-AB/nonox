<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-033 §3: the company's existing collective_agreements rows become its
 * binding to an agreement (which agreement, member or hängavtal, from and
 * to when, local overrides), and an employment records the agreement facts
 * the payroll engine reads: lönegrupp, branschvana from, and how overtime
 * and OB are compensated where the agreement allows a choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collective_agreements', function (Blueprint $table) {
            $table->string('agreement_key', 60)->nullable();
            $table->string('variant', 20)->nullable();
            $table->date('bound_from')->nullable();
            $table->date('bound_to')->nullable();
            $table->jsonb('overrides')->default('{}');
            $table->string('local_agreement_path', 500)->nullable();

            $table->index(['company_id', 'agreement_key']);
        });

        Schema::table('employments', function (Blueprint $table) {
            $table->string('agreement_group', 20)->nullable();
            $table->date('industry_experience_from')->nullable();
            $table->string('overtime_compensation', 20)->nullable();
            $table->string('ob_compensation', 20)->nullable();
        });
    }
};
