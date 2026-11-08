<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review of ADR-074 (P2-2, P2-4, P3-4):
 *
 * - An ÄTA decided orally or performed without the customer's answer keeps
 *   the PDF rendered at the decision (path and SHA-256).
 * - While an ÄTA is SENT its new status can only be SENT or a decided one,
 *   and only the decision's own columns may change.
 * - An entry's reversal link may not be set while it is on an issued invoice,
 *   and an entry may only be reserved (invoice_id from NULL) by a draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_orders', function (Blueprint $table) {
            $table->string('decided_document_path', 255)->nullable();
            $table->char('decided_document_sha256', 64)->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION change_orders_protect_decided() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'change order % cannot be removed', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status NOT IN ('DRAFT', 'SENT') THEN
                    RAISE EXCEPTION 'decided change order % cannot be changed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.status = 'SENT' THEN
                    IF NEW.status NOT IN ('SENT', 'APPROVED', 'APPROVED_ORALLY', 'PERFORMED_WITHOUT_ANSWER', 'DECLINED', 'EXPIRED', 'WITHDRAWN') THEN
                        RAISE EXCEPTION 'sent change order % can only be decided', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;

                    IF (to_jsonb(NEW) - 'status' - 'decided_at' - 'approval_method' - 'approved_by_name' - 'approved_by_title' - 'oral_channel' - 'performed_reason' - 'justification' - 'decided_by' - 'decided_document_path' - 'decided_document_sha256' - 'updated_at')
                       <> (to_jsonb(OLD) - 'status' - 'decided_at' - 'approval_method' - 'approved_by_name' - 'approved_by_title' - 'oral_channel' - 'performed_reason' - 'justification' - 'decided_by' - 'decided_document_path' - 'decided_document_sha256' - 'updated_at') THEN
                        RAISE EXCEPTION 'sent change order % cannot be changed', OLD.id
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION work_order_entries_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION '% rows cannot be removed; reverse the entry instead', TG_TABLE_NAME
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.reversed_by_id IS NOT NULL AND NEW.reversed_by_id IS DISTINCT FROM OLD.reversed_by_id THEN
                    RAISE EXCEPTION 'entry % is already reversed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF NEW.reversed_by_id IS DISTINCT FROM OLD.reversed_by_id AND OLD.invoice_id IS NOT NULL
                   AND EXISTS (SELECT 1 FROM invoices WHERE id = OLD.invoice_id AND status <> 'DRAFT') THEN
                    RAISE EXCEPTION 'entry % is invoiced and cannot be reversed', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id AND OLD.invoice_id IS NOT NULL
                   AND EXISTS (SELECT 1 FROM invoices WHERE id = OLD.invoice_id AND status <> 'DRAFT') THEN
                    RAISE EXCEPTION 'entry % is invoiced', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF NEW.invoice_id IS NOT NULL AND NEW.invoice_id IS DISTINCT FROM OLD.invoice_id
                   AND NOT EXISTS (SELECT 1 FROM invoices WHERE id = NEW.invoice_id AND status = 'DRAFT') THEN
                    RAISE EXCEPTION 'entry % can only be reserved by a draft invoice', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF (to_jsonb(NEW) - 'reversed_by_id' - 'invoice_id' - 'updated_at')
                   <> (to_jsonb(OLD) - 'reversed_by_id' - 'invoice_id' - 'updated_at') THEN
                    RAISE EXCEPTION '% rows cannot be changed; reverse the entry instead', TG_TABLE_NAME
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);
    }
};
