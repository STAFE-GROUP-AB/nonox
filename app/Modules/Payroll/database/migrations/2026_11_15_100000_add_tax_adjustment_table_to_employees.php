<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A jämkning decision (beslut om särskild beräkningsgrund, SFL 11 kap.
 * 27 §) that tells the payer to withhold by another table and column needs
 * both numbers; tax_adjustment_value holds the percentage or amount of the
 * other kinds (AUD-LON-01, #69).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedSmallInteger('tax_adjustment_table')->nullable()->after('tax_adjustment_value');
            $table->unsignedTinyInteger('tax_adjustment_column')->nullable()->after('tax_adjustment_table');
        });
    }
};
