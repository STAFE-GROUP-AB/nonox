<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The workshop wall screen (ADR-071, M7) is paired like a terminal tablet,
 * with a one-time code for a device token, so it is a row in the same
 * table with its own kind. Every existing row is a terminal tablet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_devices', function (Blueprint $table) {
            $table->string('kind', 10)->default('TERMINAL');
            $table->index(['company_id', 'kind']);
        });
    }
};
