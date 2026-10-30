<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An outlay sent with only the receipt: NONOX AI reads date, what was bought,
 * amount and VAT, and proposes a cost account for the person who approves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('ai_status', 20)->nullable();
            $table->text('ai_notes')->nullable();
            $table->integer('ai_account_number')->nullable();
        });
    }
};
