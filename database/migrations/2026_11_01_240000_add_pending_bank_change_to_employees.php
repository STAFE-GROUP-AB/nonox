<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A changed salary account waits for a second person to confirm it before
 * any payment file is made (PAYROLL_MODULE.md B.11): the new details
 * (encrypted), who asked and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->text('pending_bank')->nullable();
            $table->foreignId('pending_bank_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pending_bank_at')->nullable();
        });
    }
};
