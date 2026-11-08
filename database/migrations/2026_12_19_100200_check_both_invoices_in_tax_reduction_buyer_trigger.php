<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Review P2-3: a buyer row of an issued invoice must not be moved to a draft
 * (or from one), so an UPDATE checks both the old and the new invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoice_tax_reduction_buyers_protect_issued() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE')
                   AND EXISTS (SELECT 1 FROM invoices WHERE id = OLD.invoice_id AND status <> 'DRAFT') THEN
                    RAISE EXCEPTION 'buyers of issued invoice cannot be changed'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP IN ('INSERT', 'UPDATE')
                   AND EXISTS (SELECT 1 FROM invoices WHERE id = NEW.invoice_id AND status <> 'DRAFT') THEN
                    RAISE EXCEPTION 'buyers of issued invoice cannot be changed'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;
            SQL);
    }
};
