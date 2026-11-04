<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company is removed from the register only after two consecutive
 * complete SCB runs without it (ADR-047), so a difference between the file
 * and the API, or one bad week, does not delete it: missed_runs counts the
 * complete runs in a row that did not have it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_registry', function (Blueprint $table) {
            $table->unsignedSmallInteger('missed_runs')->default(0);
        });
    }
};
