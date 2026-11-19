<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The second review round of ADR-046 S16 (2026-11-19). The database's delete
 * gate knows only the floor from the fiscal year of completion, not later
 * bookings or a running agreement; those are checked by the prune and the
 * erasure before they delete an envelope directly. A signed envelope must
 * therefore never go by the cascade from deleting the quote, agreement or
 * ÄTA it was sent for (sales_documents, crm_agreements): the function is
 * replaced to refuse that, whatever the date. The company cascade is
 * unchanged. Function only; no column, row or constraint changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION signing_envelopes_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                today date := (now() AT TIME ZONE 'Europe/Stockholm')::date;
                floor_date date;
            BEGIN
                IF pg_trigger_depth() > 1 AND NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                    RETURN OLD;
                END IF;

                -- A signed envelope never goes with the quote, agreement or ÄTA it was sent for (the cascade from
                -- sales_documents or crm_agreements): only a direct delete by the prune or an erasure, which check the
                -- bookings and the agreement first.
                IF pg_trigger_depth() > 1
                   AND (OLD.status = 'COMPLETED' OR EXISTS (SELECT 1 FROM crm_signing_requests WHERE envelope_id = OLD.id AND status = 'SIGNED')) THEN
                    RAISE EXCEPTION 'signed signing envelope % cannot be deleted by deleting its document', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.retention_hold THEN
                    RAISE EXCEPTION 'signing envelope % is on legal hold and cannot be deleted', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                floor_date := signing_envelopes_accounting_floor(OLD);

                IF OLD.retention_until IS NOT NULL AND OLD.retention_until < today AND (floor_date IS NULL OR floor_date < today) THEN
                    RETURN OLD;
                END IF;

                IF OLD.status = 'COMPLETED' OR EXISTS (SELECT 1 FROM crm_signing_requests WHERE envelope_id = OLD.id AND status = 'SIGNED') THEN
                    RAISE EXCEPTION 'signed signing envelope % is kept until its retention date', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF EXISTS (SELECT 1 FROM crm_signing_requests WHERE envelope_id = OLD.id AND status = 'DECLINED'
                           AND (declined_at IS NULL OR declined_at >= now() - interval '12 months'))
                   AND current_setting('nonox.signing_erasure', true) IS DISTINCT FROM OLD.id::text THEN
                    RAISE EXCEPTION 'declined signing envelope % cannot be deleted before the prune period', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN OLD;
            END;
            $$;

        SQL);
    }
};
