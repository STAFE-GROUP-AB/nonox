<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The public status page says "ran / did not run" for the nightly integrity
 * check; what the run found stays in /hq (ADR-078). last_completed_at is when
 * a run finished its work, with or without findings; last_exit_code is the
 * exit code of the latest run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_heartbeats', function (Blueprint $table) {
            $table->timestampTz('last_completed_at')->nullable();
            $table->smallInteger('last_exit_code')->nullable();
        });
    }
};
