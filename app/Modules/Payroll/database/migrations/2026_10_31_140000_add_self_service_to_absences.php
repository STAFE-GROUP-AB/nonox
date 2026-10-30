<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absence an employee reports or applies for on Min sida: who asked, when it
 * was decided and, for a refusal, why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });
    }
};
