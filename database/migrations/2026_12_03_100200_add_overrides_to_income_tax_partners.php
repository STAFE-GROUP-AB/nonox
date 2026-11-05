<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * INK4DU per partner (ADR-053): contributions (8103) and withdrawals (8104)
 * a person enters instead of the amounts from the partner's BAS block, and
 * the partner's share of a regional nedsättning of arbetsgivaravgifter
 * (8105). Whole kronor; null means "from the books" or "not entered".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_tax_partners', function (Blueprint $table): void {
            $table->bigInteger('contributions_kronor')->nullable()->after('share_bp');
            $table->bigInteger('withdrawals_kronor')->nullable()->after('contributions_kronor');
            $table->bigInteger('employer_deduction_kronor')->nullable()->after('withdrawals_kronor');
        });
    }
};
