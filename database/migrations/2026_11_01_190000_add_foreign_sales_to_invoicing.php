<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales to other EU countries and exports: a customer can carry the kind
 * of foreign sale it is usually invoiced as, and an invoice then carries no
 * Swedish VAT and is booked on the account for that kind of sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('foreign_sale', 20)->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('foreign_sale', 20)->nullable();
        });
    }
};
