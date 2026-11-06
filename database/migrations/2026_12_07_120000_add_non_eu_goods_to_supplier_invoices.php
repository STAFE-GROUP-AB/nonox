<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supplier invoices for goods from outside the EU (ADR-057): the supplier
 * charges no Swedish VAT and the import VAT is booked from Tullverket's
 * tullräkning, so the invoice gets its own VAT treatment.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Laravel's enum() is a CHECK constraint on PostgreSQL; it is replaced with one that allows the new value.
        DB::statement('ALTER TABLE supplier_invoices DROP CONSTRAINT IF EXISTS supplier_invoices_vat_treatment_check');
        DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_vat_treatment_check CHECK (vat_treatment IN ('DOMESTIC', 'EU_GOODS', 'EU_SERVICES', 'NON_EU_SERVICES', 'NON_EU_GOODS', 'SE_REVERSE_GOODS', 'SE_REVERSE_SERVICES'))");
    }
};
