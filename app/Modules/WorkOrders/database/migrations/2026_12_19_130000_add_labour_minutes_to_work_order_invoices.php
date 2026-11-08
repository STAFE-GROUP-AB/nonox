<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The labour hours each invoice from a work order claims (review P1-2,
 * Lead's rule): every invoice claims the labour recorded and not yet claimed
 * by an earlier invoice of the same work order; an a conto invoice reserves
 * no time entries. Removed with the draft, which releases its claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_invoices', function (Blueprint $table) {
            $table->integer('labour_minutes')->default(0);
        });
    }
};
