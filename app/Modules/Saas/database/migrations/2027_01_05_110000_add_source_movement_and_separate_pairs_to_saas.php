<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * NONOX SaaS, after the re-check of ADR-080 step 1:
 *
 * - source_ore: a source switched off or on again (the agreement register
 *   while CRM is off) is its own row in the bridge, never churn or
 *   reactivation. Adding a column with a default fires no row trigger, so
 *   closed months keep their rows (with 0).
 * - saas_separate_pairs: a register agreement and an avtalsfakturering that
 *   a person has marked as separate agreements, so they are not listed as
 *   possible double counting. Ids only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_months', function (Blueprint $table) {
            $table->bigInteger('source_ore')->default(0);
        });

        Schema::table('saas_customer_months', function (Blueprint $table) {
            $table->bigInteger('source_ore')->default(0);
        });

        Schema::create('saas_separate_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('crm_agreement_id');
            $table->string('recurring_invoice_id', 64);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'crm_agreement_id', 'recurring_invoice_id']);
        });
    }
};
