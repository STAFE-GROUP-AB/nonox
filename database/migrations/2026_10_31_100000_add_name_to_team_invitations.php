<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The invited person's name, when the inviter already knows it (an employee
 * invited to the portal). A new account is then created with that name
 * instead of asking for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->string('name')->nullable();
        });
    }
};
