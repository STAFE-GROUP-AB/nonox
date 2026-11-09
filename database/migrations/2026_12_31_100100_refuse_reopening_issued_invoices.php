<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The protect triggers on invoices and supplier_invoices let the status column
 * change on an issued or registered invoice (it must: sent, paid, credited,
 * written off). They did not stop it going back to DRAFT, and a draft can be
 * edited and deleted, so a direct UPDATE could reopen an issued invoice and
 * then change or remove it. Going back to DRAFT is now refused. Every forward
 * transition stays as it was. payroll_runs already refuses reopening.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoices_protect_issued() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'issued invoice % cannot be deleted', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status <> 'DRAFT' AND NEW.status = 'DRAFT' THEN
                    RAISE EXCEPTION 'issued invoice % cannot be reopened as a draft', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.status <> 'DRAFT'
                   AND (to_jsonb(NEW) - 'status' - 'paid_ore' - 'credited_ore' - 'foreign_paid_minor' - 'tax_reduction_paid_ore' - 'tax_reduction_repaid_ore' - 'written_off_ore' - 'written_off_on' - 'write_off_voucher_id' - 'write_off_reason' - 'recovered_ore' - 'sent_at' - 'sent_to' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'paid_ore' - 'credited_ore' - 'foreign_paid_minor' - 'tax_reduction_paid_ore' - 'tax_reduction_repaid_ore' - 'written_off_ore' - 'written_off_on' - 'write_off_voucher_id' - 'write_off_reason' - 'recovered_ore' - 'sent_at' - 'sent_to' - 'updated_at') THEN
                    RAISE EXCEPTION 'issued invoice % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION supplier_invoices_protect_registered() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'registered supplier invoice % cannot be deleted', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status <> 'DRAFT' AND NEW.status = 'DRAFT' THEN
                    RAISE EXCEPTION 'registered supplier invoice % cannot be reopened as a draft', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.status <> 'DRAFT'
                   AND (to_jsonb(NEW) - 'status' - 'paid_ore' - 'foreign_paid_minor' - 'approved_at' - 'approved_by' - 'updated_at')
                     <> (to_jsonb(OLD) - 'status' - 'paid_ore' - 'foreign_paid_minor' - 'approved_at' - 'approved_by' - 'updated_at') THEN
                    RAISE EXCEPTION 'registered supplier invoice % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);
    }
};
