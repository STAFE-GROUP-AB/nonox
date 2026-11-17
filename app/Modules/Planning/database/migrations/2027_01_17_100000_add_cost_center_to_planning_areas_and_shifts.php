<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shifts on cost centres (ADR-037, gap 7): an area's default cost centre,
 * and the cost centre of each shift, taken from its area when it is saved
 * and changeable per shift. Null on a shift means the employment's cost
 * centre, as before. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planning_areas', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
        });

        Schema::table('planning_shifts', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
        });
    }
};
