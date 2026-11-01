<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A key derived from a person's PIN with its own salt. It seals the control
 * visit data a kiosk keeps for working offline, so only someone who knows a
 * manager's PIN can open it (ADR-022). Stored encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_ledger_people', function (Blueprint $table) {
            $table->char('control_salt', 32)->nullable();
            $table->text('control_key')->nullable();
        });
    }
};
