<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * NE-bilaga and Inkomstdeklaration 4 (ADR-053). A return is now one row per
 * fiscal year and form (INK2, NE or INK4), as the NE codes overlap the INK2
 * codes. `inputs` holds what the owner confirms for the calculations
 * (kapitalunderlag, the räntefördelning used, the expansionsfond), never
 * personal data; `personal` holds the little personal data a calculation
 * needs (the owner's century of birth), encrypted. The partners of a
 * handelsbolag are kept per fiscal year with their share; the identity
 * (personnummer or organisationsnummer) is encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_tax_returns', function (Blueprint $table): void {
            $table->string('form', 8)->default('INK2')->after('fiscal_year_id');
            $table->jsonb('inputs')->nullable()->after('adjustments');
            $table->text('personal')->nullable()->after('inputs');
            $table->dropUnique(['fiscal_year_id']);
            $table->unique(['fiscal_year_id', 'form']);
        });

        Schema::create('income_tax_partners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('name');
            $table->text('identity');
            $table->boolean('legal_person')->default(false);
            $table->unsignedSmallInteger('share_bp');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'position']);
        });
    }
};
