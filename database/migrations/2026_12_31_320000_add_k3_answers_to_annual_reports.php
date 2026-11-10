<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Review of S1-P9 (ADR-059 A.2.7): a person's yes/no answers behind the K3
 * notes that the books cannot tell (whether there are commitments for group
 * companies, off-balance arrangements, loans to executives, a parent company,
 * a material error corrected retroactively), so a mandatory note is never
 * left out silently; and the K3 3.7 text on important changes in the business.
 * The K2 report ignores both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_reports', function (Blueprint $table): void {
            $table->jsonb('k3_answers')->nullable();
            $table->text('k3_business_changes')->nullable();
        });
    }
};
