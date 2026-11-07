<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Shares a company holds in another legal person (ADR-060, addendum
 * 2026-11-07, 2.1): the holder's own register of shares in group companies.
 * The held company is named by organisation number and name, never by
 * another tenant's id, exactly like a customer row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_holdings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('held_org_number', 11);
            $table->string('held_name');
            // Basis points, 10000 = 100 %. Capital and votes are kept apart (S2-R1d, S2-R8).
            $table->unsignedSmallInteger('capital_bp');
            $table->unsignedSmallInteger('voting_bp');
            // ÅRL 1 kap. 4 § points 1–4, entered by a person, never derived from voting_bp.
            $table->string('control_basis', 30);
            $table->date('acquired_on');
            $table->date('disposed_on')->nullable();
            // S2-R4e / H2: a combination under common control; null = not answered.
            $table->boolean('common_control')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'held_org_number']);
            $table->index('held_org_number');
        });

        DB::statement('ALTER TABLE company_holdings ADD CONSTRAINT company_holdings_shares_check CHECK (capital_bp BETWEEN 0 AND 10000 AND voting_bp BETWEEN 0 AND 10000)');
        DB::statement('ALTER TABLE company_holdings ADD CONSTRAINT company_holdings_dates_check CHECK (disposed_on IS NULL OR disposed_on >= acquired_on)');
    }
};
