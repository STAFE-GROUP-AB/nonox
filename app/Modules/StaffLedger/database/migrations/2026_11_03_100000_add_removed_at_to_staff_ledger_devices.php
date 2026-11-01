<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A kiosk that registered entries cannot be deleted: the ledger keeps which
 * device sent each entry. Removing it hides it from the lists instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_ledger_devices', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users');
        });
    }
};
