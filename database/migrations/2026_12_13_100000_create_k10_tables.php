<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * K10 underlag for the owners of a fåmansföretag (ADR-062). One row per
 * company and inkomstår with what holds for the whole company (all shares,
 * a wage grant, the year's dividend when it is entered, the confirmation that
 * the shares are qualified), and one row per owner and inkomstår. The owner's
 * personnummer is optional, only for the SRU file, and encrypted. Nothing is
 * räkenskapsinformation and nothing is booked; a row can be deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('k10_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('income_year');
            $table->unsignedBigInteger('shares_total')->nullable();
            $table->unsignedBigInteger('wage_subsidy_kronor')->nullable();
            $table->unsignedBigInteger('dividend_total_kronor')->nullable();
            $table->boolean('qualified_confirmed')->default(false);
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'income_year']);
        });

        Schema::create('k10_owners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('income_year');
            $table->string('name', 200);
            $table->text('identity')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('spouse_owner_id')->nullable()->constrained('k10_owners')->nullOnDelete();
            $table->unsignedBigInteger('shares_owned');
            $table->date('acquired_on')->nullable();
            $table->unsignedInteger('other_holdings_bp')->nullable();
            $table->unsignedBigInteger('cost_basis_kronor')->nullable();
            $table->unsignedBigInteger('saved_space_kronor')->nullable();
            $table->unsignedBigInteger('own_pay_kronor')->nullable();
            $table->unsignedBigInteger('related_pay_kronor')->nullable();
            $table->unsignedBigInteger('company_pay_kronor')->nullable();
            $table->unsignedBigInteger('subsidiary_pay_kronor')->nullable();
            $table->unsignedBigInteger('dividend_kronor')->nullable();
            $table->boolean('transferred_shares')->default(false);
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'income_year']);
        });
    }
};
