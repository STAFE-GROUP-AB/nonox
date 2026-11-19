<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The review round of ADR-046 S16 (2026-11-19). Replaced functions and one
 * new trigger; no column, row or constraint changes.
 *
 * - A new envelope never arrives with a retention class, date or hold: they
 *   are set only by the update that finishes or expires it (where the floor
 *   trigger checks them), so an insert cannot bypass the BFL floor.
 * - The delete gate also checks the BFL floor of an ACCOUNTING envelope
 *   itself, not only its stamped date.
 * - One person's erasure may delete that person's own declined signer row in
 *   an envelope that stays (the transaction-local setting
 *   nonox.signing_erasure names the row as 'request:<id>'); a signed row,
 *   or a row of a held envelope, still never goes on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION signing_envelopes_retention_on_insert() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF NEW.retention_class IS NOT NULL OR NEW.retention_until IS NOT NULL OR NEW.retention_hold THEN
                    RAISE EXCEPTION 'A signing envelope gets its retention when it finishes, never on insert (ADR-046 S16).';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_envelopes_retention_on_insert BEFORE INSERT ON signing_envelopes
                FOR EACH ROW EXECUTE FUNCTION signing_envelopes_retention_on_insert();

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

            CREATE OR REPLACE FUNCTION crm_signing_requests_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1 AND NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                    RETURN OLD;
                END IF;

                IF pg_trigger_depth() > 1 AND OLD.envelope_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM signing_envelopes WHERE id = OLD.envelope_id) THEN
                    RETURN OLD;
                END IF;

                IF OLD.envelope_id IS NOT NULL AND EXISTS (SELECT 1 FROM signing_envelopes WHERE id = OLD.envelope_id AND retention_hold) THEN
                    RAISE EXCEPTION 'signing request % belongs to an envelope on legal hold', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.status = 'SIGNED' THEN
                    RAISE EXCEPTION 'signed signing request % cannot be deleted', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.status = 'DECLINED' AND (OLD.declined_at IS NULL OR OLD.declined_at >= now() - interval '12 months')
                   AND current_setting('nonox.signing_erasure', true) IS DISTINCT FROM 'request:' || OLD.id::text THEN
                    RAISE EXCEPTION 'declined signing request % cannot be deleted before the prune period', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN OLD;
            END;
            $$;
        SQL);
    }
};
