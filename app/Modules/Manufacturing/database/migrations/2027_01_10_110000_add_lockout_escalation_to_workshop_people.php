<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A four-digit code can be guessed over time: five wrong codes lock a person
 * for five minutes, but that alone would allow about 43 000 guesses a day
 * against 10 000 codes. Repeated lock-outs therefore escalate: after three
 * within 24 hours the person stays blocked until a manager sets a new code
 * (ADR-071 §7, review of M5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_people', function (Blueprint $table) {
            $table->unsignedSmallInteger('lockouts')->default(0);
            $table->timestamp('last_lockout_at')->nullable();
            $table->timestamp('code_blocked_at')->nullable();
        });
    }
};
