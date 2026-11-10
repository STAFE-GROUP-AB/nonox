<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Whether an approval linked the entry to a user that already existed
 * (ADR-038 addendum): someone who already had an account needs no reminder
 * to log in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->boolean('approved_user_existed')->default(false)->after('approved_user_id');
        });
    }
};
