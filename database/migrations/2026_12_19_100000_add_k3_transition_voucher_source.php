<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A new voucher source, K3_TRANSITION, for the voucher that books the K2-to-K3
 * transition differences against equity on the first day of the first K3
 * year (ADR-059, human decision H7 (a), S1-P12; K3 35.11). Only the check of
 * vouchers.source changes: no trigger and no PostVoucher change. The list is
 * written out, so the migration means the same whenever it runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE vouchers DROP CONSTRAINT IF EXISTS vouchers_source_check');
        DB::statement(<<<'SQL'
            ALTER TABLE vouchers ADD CONSTRAINT vouchers_source_check CHECK (source::text IN (
                'MANUAL', 'INVOICE', 'PAYMENT', 'SUPPLIER_INVOICE', 'VAT', 'PAYROLL',
                'IMPORT_SIE', 'IMPORT_FORTNOX', 'CLOSING', 'CORRECTION', 'K3_TRANSITION'
            ))
        SQL);
    }
};
