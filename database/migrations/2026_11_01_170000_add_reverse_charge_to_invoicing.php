<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Omvänd betalningsskyldighet for construction services: a customer can be
 * marked as one the company sells construction services to, and an invoice
 * then carries no VAT and the buyer reports it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('reverse_charge_construction')->default(false);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('reverse_charge')->default(false);
        });
    }
};
