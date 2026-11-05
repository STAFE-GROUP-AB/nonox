<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Momsbufferten (ADR-051, stage 3): the monthly debited preliminary tax from
 * the company's decision, entered by the company if it wants the coming
 * payments shown. Optional; NONOX never computes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->bigInteger('preliminary_tax_monthly_ore')->nullable()->after('f_skatt');
        });
    }
};
