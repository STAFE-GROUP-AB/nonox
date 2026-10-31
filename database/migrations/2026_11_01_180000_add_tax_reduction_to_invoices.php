<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROT and RUT (skattereduktion för hushållsarbete): an invoice to a private
 * person can carry a deduction on its labour rows that Skatteverket pays to
 * the company instead of the customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('tax_reduction', 3)->nullable();
            $table->bigInteger('tax_reduction_ore')->default(0);
            $table->bigInteger('tax_reduction_paid_ore')->default(0);
            $table->string('property_designation')->nullable();
        });

        Schema::table('invoice_rows', function (Blueprint $table) {
            $table->boolean('is_labour')->default(false);
        });
    }
};
