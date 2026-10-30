<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier credit notes, and invoices where the buyer accounts for the VAT
 * (purchases from abroad and Swedish reverse charge), ADR-016.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->enum('kind', ['INVOICE', 'CREDIT'])->default('INVOICE');
            $table->enum('vat_treatment', ['DOMESTIC', 'EU_GOODS', 'EU_SERVICES', 'NON_EU_SERVICES', 'SE_REVERSE_GOODS', 'SE_REVERSE_SERVICES'])->default('DOMESTIC');
        });

        Schema::table('supplier_invoice_rows', function (Blueprint $table) {
            // The VAT rate the buyer accounts for on this row, in basis points, when the buyer is liable.
            $table->unsignedSmallInteger('vat_rate')->nullable();
        });
    }
};
