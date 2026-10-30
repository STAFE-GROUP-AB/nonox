<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an employee was last invited to the employee portal (Min sida), so
 * the employee list can show who has been invited and who has logged in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('portal_invited_at')->nullable();
        });
    }
};
