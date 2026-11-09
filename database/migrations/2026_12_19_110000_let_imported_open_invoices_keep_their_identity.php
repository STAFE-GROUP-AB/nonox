<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-076 (review): an imported open invoice keeps the number it had in the
 * previous program as its identity (external_ref) and takes no number from
 * the NONOX invoice series. The checks that demand a number on an issued
 * invoice, and an approver on an approved supplier invoice, therefore make
 * an exception for rows marked import_source (IMPORT_OPEN).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE invoices DROP CONSTRAINT invoices_issued_has_number;
            ALTER TABLE invoices ADD CONSTRAINT invoices_issued_has_number
                CHECK (status = 'DRAFT' OR import_source IS NOT NULL OR (number IS NOT NULL AND issued_at IS NOT NULL));

            ALTER TABLE supplier_invoices DROP CONSTRAINT supplier_invoices_registered_has_number;
            ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_registered_has_number
                CHECK (status = 'DRAFT' OR import_source IS NOT NULL OR (number IS NOT NULL AND registered_at IS NOT NULL));

            ALTER TABLE supplier_invoices DROP CONSTRAINT supplier_invoices_approved_has_approver;
            ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_approved_has_approver
                CHECK (status IN ('DRAFT', 'REGISTERED') OR import_source IS NOT NULL OR (approved_at IS NOT NULL AND approved_by IS NOT NULL));
            SQL);
    }
};
