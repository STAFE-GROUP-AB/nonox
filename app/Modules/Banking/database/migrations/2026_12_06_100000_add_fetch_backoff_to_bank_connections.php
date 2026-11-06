<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Launch hardening of NONOX Banking (ADR-034, addendum 2026-11-06): a
 * connection whose provider fails is tried again after growing pauses
 * (15 minutes, 1 hour, 4 hours, 12 hours, then once a day) instead of
 * every hour, so a provider that is down is not hammered and the bank's
 * cap on fetches without the user present is not used up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_connections', function (Blueprint $table) {
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
        });
    }
};
