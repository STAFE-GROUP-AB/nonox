<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day a time report's agreement rate is taken from, when it differs
 * from the day the report is paid with (ADR-033 H.1 fix): a calculation
 * period's overtime is sent with the period's last day, but each hour is
 * priced at the rate step in force on the day it was worked (Gröna Riks
 * § 12 p. 1.2, Detaljhandeln § 9.2 give the rates by the date the overtime
 * is performed). Null means the report's own date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_reports', function (Blueprint $table) {
            $table->date('priced_on')->nullable();
        });
    }
};
