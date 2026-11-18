<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The hash-chained audit trail of a signing envelope (ADR-046, amendment
 * 2026-11-18, gap 4, package S2).
 *
 * Every event of an envelope is chained: hash = sha256(previous_hash ||
 * canonical_json(event)), and the first event's previous_hash is the SHA-256
 * of the document that was sent. An insert takes the envelope's row lock, so
 * the sequence has no gaps; the trigger below checks every link again. The
 * head is kept on the envelope when it finishes and written to the audit
 * log. signing_document_versions holds every file of an envelope with its
 * SHA-256 (the original from now on; the stamped and sealed versions come
 * with S6 and S10).
 *
 * Additive only: new nullable columns, a new table, new triggers, and the
 * event column widened from 10 to 20 characters (CHAIN_STARTED is 13; a
 * longer varchar limit is a catalogue change in PostgreSQL and rewrites no
 * row). The evidence triggers crm_signing_events_append_only and
 * crm_signing_events_delete_with_request stay exactly as they are, so a
 * stored event still can never be updated.
 *
 * Backfill (insert only, in 2027_02_03_100100 after the counter exists): every existing envelope gets the ORIGINAL row in
 * signing_document_versions and a CHAIN_STARTED event, whose payload holds
 * the count and a digest of the envelope's earlier, unchained events, per
 * signer. A finished envelope also gets its head and an audit entry
 * (signing.chain_backfilled). Nothing that exists is changed; the envelope
 * gains only chain_head and chain_sequence, set once from null.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE crm_signing_events ALTER COLUMN event TYPE varchar(20)');

        Schema::table('crm_signing_events', function (Blueprint $table) {
            // No foreign key on purpose: events go with their signing request (the evidence delete trigger); a second
            // cascade path from the envelope could reach an event while its request still exists, and be refused.
            $table->uuid('envelope_id')->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();
            // Ids, enums, counts and hashes only: never names, addresses or values.
            $table->jsonb('payload')->nullable();

            $table->unique(['envelope_id', 'sequence']);
            $table->index(['company_id', 'envelope_id']);
        });

        Schema::table('signing_envelopes', function (Blueprint $table) {
            // The last hash of the chain when the envelope finished, and its sequence number. Set once.
            $table->char('chain_head', 64)->nullable();
            $table->unsignedInteger('chain_sequence')->nullable();
        });

        Schema::create('signing_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->uuid('envelope_id');
            $table->foreign('envelope_id')->references('id')->on('signing_envelopes')->cascadeOnDelete();
            // ORIGINAL (S2), AFTER_SIGNER (S6), SEALED (S10), TIMESTAMPED (S11).
            $table->string('kind', 12);
            $table->foreignId('signing_request_id')->nullable()->constrained('crm_signing_requests')->cascadeOnDelete();
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('bytes')->nullable();
            $table->timestampTz('created_at');

            $table->index(['company_id', 'envelope_id']);
            $table->index('sha256');
            $table->index('envelope_id');
        });

        DB::statement("ALTER TABLE signing_document_versions ADD CONSTRAINT signing_document_versions_kind_check CHECK (kind IN ('ORIGINAL', 'AFTER_SIGNER', 'SEALED', 'TIMESTAMPED'))");

        $this->guards();

        // The backfill runs in the next migration, once the envelope keeps its chain counter.
    }

    /** The triggers of the chain. CREATE OR REPLACE, so running it again gives the same result. */
    public function guards(): void
    {
        DB::unprepared(<<<'SQL'
            -- An event of an envelope is a link in its chain: the next sequence number, the hash of the link before it
            -- (the document's SHA-256 for the first), and the envelope of its own signing request. Once an envelope's
            -- chain has started, an unchained event for it is refused. The app computes the hash; this checks the links.
            CREATE OR REPLACE FUNCTION crm_signing_events_chain() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                request_envelope uuid;
                expected_sequence integer;
                expected_previous char(64);
            BEGIN
                SELECT envelope_id INTO request_envelope FROM crm_signing_requests WHERE id = NEW.signing_request_id;

                IF NEW.envelope_id IS NULL THEN
                    IF NEW.sequence IS NOT NULL OR NEW.previous_hash IS NOT NULL OR NEW.hash IS NOT NULL THEN
                        RAISE EXCEPTION 'A chained signing event needs its envelope (ADR-046 S2).';
                    END IF;

                    IF request_envelope IS NOT NULL
                        AND EXISTS (SELECT 1 FROM crm_signing_events WHERE envelope_id = request_envelope) THEN
                        RAISE EXCEPTION 'The envelope''s events are chained; an unchained event is refused (ADR-046 S2).';
                    END IF;

                    RETURN NEW;
                END IF;

                IF request_envelope IS DISTINCT FROM NEW.envelope_id THEN
                    RAISE EXCEPTION 'A signing event belongs to the envelope of its signing request (ADR-046 S2).';
                END IF;

                IF NEW.sequence IS NULL OR NEW.previous_hash IS NULL OR NEW.hash IS NULL
                    OR NEW.hash !~ '^[0-9a-f]{64}$' OR NEW.previous_hash !~ '^[0-9a-f]{64}$' THEN
                    RAISE EXCEPTION 'A chained signing event needs its sequence and hashes (ADR-046 S2).';
                END IF;

                SELECT coalesce(max(sequence), 0) + 1 INTO expected_sequence FROM crm_signing_events WHERE envelope_id = NEW.envelope_id;

                IF NEW.sequence <> expected_sequence THEN
                    RAISE EXCEPTION 'Signing event % of the envelope is out of sequence (expected %) (ADR-046 S2).', NEW.sequence, expected_sequence;
                END IF;

                IF expected_sequence = 1 THEN
                    SELECT document_sha256 INTO expected_previous FROM signing_envelopes WHERE id = NEW.envelope_id AND company_id = NEW.company_id;
                ELSE
                    SELECT hash INTO expected_previous FROM crm_signing_events WHERE envelope_id = NEW.envelope_id AND sequence = expected_sequence - 1;
                END IF;

                IF expected_previous IS NULL OR NEW.previous_hash <> expected_previous THEN
                    RAISE EXCEPTION 'Signing event % does not link to the one before it (ADR-046 S2).', NEW.sequence;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE TRIGGER crm_signing_events_chain BEFORE INSERT ON crm_signing_events
                FOR EACH ROW EXECUTE FUNCTION crm_signing_events_chain();

            -- The head of a finished envelope's chain is set once, from null, and never changed.
            CREATE OR REPLACE FUNCTION signing_envelopes_protect_chain() RETURNS trigger AS $$
            BEGIN
                IF (OLD.chain_head IS NOT NULL AND NEW.chain_head IS DISTINCT FROM OLD.chain_head)
                    OR (OLD.chain_sequence IS NOT NULL AND NEW.chain_sequence IS DISTINCT FROM OLD.chain_sequence)
                    OR ((NEW.chain_head IS NULL) <> (NEW.chain_sequence IS NULL)) THEN
                    RAISE EXCEPTION 'An envelope''s chain head is set once and never changed (ADR-046 S2).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_envelopes_protect_chain BEFORE UPDATE ON signing_envelopes
                FOR EACH ROW EXECUTE FUNCTION signing_envelopes_protect_chain();

            -- A document version is evidence: never changed.
            CREATE OR REPLACE FUNCTION signing_document_versions_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Signing document versions are append-only (ADR-046 S2).';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_document_versions_append_only BEFORE UPDATE ON signing_document_versions
                FOR EACH ROW EXECUTE FUNCTION signing_document_versions_append_only();

            -- Deleted only with its envelope (or signer row, or the whole company), by the foreign key's cascade.
            CREATE OR REPLACE FUNCTION signing_document_versions_delete_with_envelope() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1
                   AND (NOT EXISTS (SELECT 1 FROM signing_envelopes WHERE id = OLD.envelope_id)
                        OR (OLD.signing_request_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM crm_signing_requests WHERE id = OLD.signing_request_id))
                        OR NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id)) THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'Signing document versions are removed only with their envelope (ADR-046 S2).'
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_document_versions_delete_with_envelope BEFORE DELETE ON signing_document_versions
                FOR EACH ROW EXECUTE FUNCTION signing_document_versions_delete_with_envelope();
        SQL);
    }
};
