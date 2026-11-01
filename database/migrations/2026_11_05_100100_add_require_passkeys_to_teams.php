<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Kräv passkey för alla i företaget" (ADR-029): when the owner turns it on,
 * every member of the company must sign in with a passkey, not only those who
 * can see health data or full personal numbers. Off by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('require_passkeys')->default(false);
        });
    }
};
