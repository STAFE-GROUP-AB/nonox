<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day of the month an agreement invoices on, so an agreement starting on
 * the 31st invoices on the last day of shorter months and on the 31st again
 * after them, instead of drifting to the 28th.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoices', function (Blueprint $table) {
            $table->unsignedTinyInteger('invoice_day')->default(1);
        });
    }
};
