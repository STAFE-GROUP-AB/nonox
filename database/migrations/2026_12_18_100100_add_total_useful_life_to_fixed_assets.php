<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The total useful life of a component (K3 17.23 "tillämpad nyttjandeperiod",
 * ADR-059 A.2.4, S1-P4 review): useful_life_months of a component created by
 * a split is the remaining life its plan runs over; the principles note
 * states the total. Null for every asset that is not a component.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->integer('total_useful_life_months')->nullable();
        });
    }
};
