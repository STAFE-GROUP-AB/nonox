<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * K10 (ADR-062, tax review): an owner who held shares all of the year before
 * and bought more during it needs two separate calculations (IL 57 kap.
 * 16 a §, K10 p. 4.1–4.4 and 4.5–4.8), which NONOX refuses; and a saved
 * dividend space copied from last year's underlag is marked as such, so it
 * is not taken for the owner's own entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('k10_owners', function (Blueprint $table): void {
            $table->boolean('topped_up_previous_year')->default(false)->after('acquired_on');
            $table->boolean('saved_space_carried')->default(false)->after('saved_space_kronor');
        });
    }
};
