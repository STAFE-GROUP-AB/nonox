<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Radera mitt konto" (GDPR art. 17): an account that others' bookkeeping
 * still refers to is pseudonymised instead of deleted, and this marks it.
 * A pseudonymised account can never sign in again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymised_at')->nullable();
        });
    }
};
