<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a person adds to a fiscal year's income tax return (INK2): the tax
 * adjustments in whole kronor by field code, and whether a consultant
 * helped and the annual report was audited. The rest comes from the books.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_tax_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->jsonb('adjustments');
            $table->boolean('consultant')->nullable();
            $table->boolean('audited')->nullable();
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();

            $table->unique('fiscal_year_id');
        });
    }
};
