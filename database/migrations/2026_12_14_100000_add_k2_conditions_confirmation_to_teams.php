<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Owner decision 16: a K2 company confirms the conditions of BFNAR 2016:10
 * punkt 1.1A-1.1C (as amended by BFNAR 2025:2) once, the next time the
 * closing is opened for a fiscal year that starts after 2025-12-31. Existing
 * companies have no confirmation and are asked; a company that confirms the
 * conditions when choosing K2 is stamped at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->timestampTz('k2_conditions_confirmed_at')->nullable();
            $table->foreignId('k2_conditions_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};
