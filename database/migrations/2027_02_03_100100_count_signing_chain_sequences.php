<?php

declare(strict_types=1);

use App\Domain\Signing\Chain\SigningChainBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The chain counter of a signing envelope (ADR-046 amendment, gap 4,
 * package S2, review round). The next sequence number and the last hash of
 * an envelope's chain live on the envelope (next_sequence, last_hash) and
 * are kept by the insert trigger only, so a pruned signer's events (which go
 * with the signer row) never free a sequence number for reuse, and the chain
 * goes on from the last link even when that link has been pruned.
 *
 * The insert trigger is replaced: an event must also belong to the company
 * of its signer row and envelope, and no event is added to an envelope whose
 * head has been set (a finished chain). The counter can be moved only by the
 * insert trigger (pg_trigger_depth) and never backwards.
 *
 * Then the backfill (SigningChainBackfill): it calls the application class
 * on purpose, so the backfill and signing:verify share one canonical JSON
 * and one hash (EventHash). A copy frozen here could drift from the
 * verifier and turn every backfilled envelope into a false alarm. The class
 * inserts rows only and is idempotent (an envelope whose counter has moved
 * is left alone); the migration runs it once.
 *
 * Additive only: two new columns, replaced and new trigger functions, rows
 * inserted. A lock timeout keeps a busy production table from stalling the
 * deploy: the migration fails and is run again instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        DB::statement('ALTER TABLE signing_envelopes ADD COLUMN IF NOT EXISTS next_sequence integer NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE signing_envelopes ADD COLUMN IF NOT EXISTS last_hash char(64) NULL');

        // Envelopes chained before this migration (none in production: the backfill runs below).
        DB::unprepared(<<<'SQL'
            UPDATE signing_envelopes e
                SET next_sequence = c.top + 1, last_hash = c.hash
                FROM (
                    SELECT DISTINCT ON (envelope_id) envelope_id, sequence AS top, hash
                    FROM crm_signing_events
                    WHERE envelope_id IS NOT NULL
                    ORDER BY envelope_id, sequence DESC
                ) c
                WHERE e.id = c.envelope_id AND e.next_sequence = 1;
            SQL);

        $this->guards();

        app(SigningChainBackfill::class)->run();
    }

    public function guards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION crm_signing_events_chain() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                request_envelope uuid;
                request_company bigint;
                envelope_company bigint;
                envelope_document char(64);
                envelope_next integer;
                envelope_last char(64);
                envelope_head char(64);
            BEGIN
                SELECT envelope_id, company_id INTO request_envelope, request_company
                    FROM crm_signing_requests WHERE id = NEW.signing_request_id;

                IF NEW.company_id IS DISTINCT FROM request_company THEN
                    RAISE EXCEPTION 'A signing event belongs to the company of its signing request (ADR-046 S2).';
                END IF;

                IF NEW.envelope_id IS NULL THEN
                    IF NEW.sequence IS NOT NULL OR NEW.previous_hash IS NOT NULL OR NEW.hash IS NOT NULL THEN
                        RAISE EXCEPTION 'A chained signing event needs its envelope (ADR-046 S2).';
                    END IF;

                    IF request_envelope IS NOT NULL
                        AND (SELECT next_sequence FROM signing_envelopes WHERE id = request_envelope) > 1 THEN
                        RAISE EXCEPTION 'The envelope''s events are chained; an unchained event is refused (ADR-046 S2).';
                    END IF;

                    RETURN NEW;
                END IF;

                IF request_envelope IS DISTINCT FROM NEW.envelope_id THEN
                    RAISE EXCEPTION 'A signing event belongs to the envelope of its signing request (ADR-046 S2).';
                END IF;

                SELECT company_id, document_sha256, next_sequence, last_hash, chain_head
                    INTO envelope_company, envelope_document, envelope_next, envelope_last, envelope_head
                    FROM signing_envelopes WHERE id = NEW.envelope_id FOR UPDATE;

                IF envelope_company IS DISTINCT FROM NEW.company_id THEN
                    RAISE EXCEPTION 'A signing event belongs to the company of its envelope (ADR-046 S2).';
                END IF;

                IF envelope_head IS NOT NULL THEN
                    RAISE EXCEPTION 'The envelope''s chain is closed; no event can be added (ADR-046 S2).';
                END IF;

                IF NEW.sequence IS NULL OR NEW.previous_hash IS NULL OR NEW.hash IS NULL
                    OR NEW.hash !~ '^[0-9a-f]{64}$' OR NEW.previous_hash !~ '^[0-9a-f]{64}$' THEN
                    RAISE EXCEPTION 'A chained signing event needs its sequence and hashes (ADR-046 S2).';
                END IF;

                IF NEW.sequence <> envelope_next THEN
                    RAISE EXCEPTION 'Signing event % of the envelope is out of sequence (expected %) (ADR-046 S2).', NEW.sequence, envelope_next;
                END IF;

                IF NEW.previous_hash <> coalesce(envelope_last, envelope_document) THEN
                    RAISE EXCEPTION 'Signing event % does not link to the one before it (ADR-046 S2).', NEW.sequence;
                END IF;

                UPDATE signing_envelopes SET next_sequence = NEW.sequence + 1, last_hash = NEW.hash WHERE id = NEW.envelope_id;

                RETURN NEW;
            END;
            $$;

            -- The counter is moved only by the insert trigger above, and never backwards.
            CREATE OR REPLACE FUNCTION signing_envelopes_protect_counter() RETURNS trigger AS $$
            BEGIN
                IF NEW.next_sequence IS DISTINCT FROM OLD.next_sequence OR NEW.last_hash IS DISTINCT FROM OLD.last_hash THEN
                    IF pg_trigger_depth() < 2 OR NEW.next_sequence <= OLD.next_sequence OR NEW.last_hash IS NULL THEN
                        RAISE EXCEPTION 'An envelope''s chain counter is kept by its events only (ADR-046 S2).';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_envelopes_protect_counter BEFORE UPDATE ON signing_envelopes
                FOR EACH ROW EXECUTE FUNCTION signing_envelopes_protect_counter();
        SQL);
    }
};
