<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Envelopes (ADR-046, amendment 2026-11-18, gap 1, package S1). An envelope
 * holds one document, up to ten signers and up to ten copy recipients.
 * crm_signing_requests becomes the signer row: it gains envelope_id and its
 * step (1–10; signers on the same step sign in parallel). Links for a
 * signer in an envelope with more than one signer live in
 * signing_link_tokens, issued only when the signer's step opens.
 *
 * Additive only: new tables, two new columns, new triggers. The existing
 * evidence triggers on crm_signing_requests (crm_signing_requests_protect,
 * _protect_channel, _protect_delete) are not touched. A new trigger lets
 * envelope_id be set once from null (the backfill below, also on signed and
 * declined rows) and never changed after; the step never changes once the
 * row belongs to an envelope.
 *
 * Backfill: one envelope per existing request, inserting rows only, with
 * the status derived from the request (PENDING → OUT, SIGNED → COMPLETED,
 * DECLINED → DECLINED, REVOKED → VOIDED), so every request has an envelope
 * and existing links keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_envelopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // The document, in the same columns as the signing request (SignableTypes).
            $table->string('document_type', 10);
            $table->uuid('sales_document_id')->nullable();
            $table->foreign('sales_document_id')->references('id')->on('sales_documents')->cascadeOnDelete();
            $table->foreignId('agreement_id')->nullable()->constrained('crm_agreements')->cascadeOnDelete();
            $table->string('signable_id', 64)->nullable();
            // A snapshot of the document's title when it was sent.
            $table->string('title')->nullable();
            // DRAFT, OUT, COMPLETED, DECLINED, VOIDED (expired is derived from deadline_at).
            $table->string('status', 10)->default('OUT');
            $table->unsignedSmallInteger('current_step')->default(1);
            $table->string('document_path');
            $table->string('document_name');
            $table->char('document_sha256', 64);
            $table->char('content_hash', 64)->nullable();
            $table->timestampTz('deadline_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('followed_up_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'document_type', 'sales_document_id']);
            $table->index(['company_id', 'agreement_id']);
            $table->index(['company_id', 'document_type', 'signable_id']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_status_check CHECK (status IN ('DRAFT', 'OUT', 'COMPLETED', 'DECLINED', 'VOIDED'))");
        DB::statement('ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_step_check CHECK (current_step BETWEEN 1 AND 10)');

        Schema::table('crm_signing_requests', function (Blueprint $table) {
            $table->uuid('envelope_id')->nullable()->after('company_id');
            $table->foreign('envelope_id')->references('id')->on('signing_envelopes')->cascadeOnDelete();
            $table->unsignedSmallInteger('step')->default(1)->after('envelope_id');
            $table->index(['company_id', 'envelope_id']);
        });

        DB::statement('ALTER TABLE crm_signing_requests ADD CONSTRAINT crm_signing_requests_step_check CHECK (step BETWEEN 1 AND 10)');

        Schema::create('signing_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('signing_request_id')->constrained('crm_signing_requests')->cascadeOnDelete();
            // SHA-256 of the 256-bit token; the token itself is only in the e-mail.
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('issued_at');
            // SENT, REMINDER or RESENT.
            $table->string('reason', 10)->default('SENT');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'signing_request_id']);
        });

        Schema::create('signing_copy_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->uuid('envelope_id');
            $table->foreign('envelope_id')->references('id')->on('signing_envelopes')->cascadeOnDelete();
            $table->string('email');
            $table->string('name', 120)->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->timestampTz('sent_copy_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'envelope_id']);
            $table->unique(['envelope_id', 'email']);
        });

        $this->guards();
        $this->backfill();
    }

    /**
     * The indexes and the triggers that guard the envelope tables. Written
     * with CREATE OR REPLACE and IF NOT EXISTS, so running it again gives the
     * same result.
     */
    public function guards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE INDEX IF NOT EXISTS crm_signing_requests_envelope_id_index ON crm_signing_requests (envelope_id);
            CREATE INDEX IF NOT EXISTS signing_link_tokens_signing_request_id_index ON signing_link_tokens (signing_request_id);

            -- A signer row's envelope is set once (from null) and never changed; its step never changes once it has an envelope.
            CREATE OR REPLACE FUNCTION crm_signing_requests_protect_envelope() RETURNS trigger AS $$
            BEGIN
                IF OLD.envelope_id IS NOT NULL AND NEW.envelope_id IS DISTINCT FROM OLD.envelope_id THEN
                    RAISE EXCEPTION 'A signing request''s envelope cannot be changed (ADR-046 S1).';
                END IF;
                IF OLD.envelope_id IS NOT NULL AND NEW.step IS DISTINCT FROM OLD.step THEN
                    RAISE EXCEPTION 'A signer''s step cannot be changed (ADR-046 S1).';
                END IF;
                IF OLD.status IN ('SIGNED', 'DECLINED') AND OLD.envelope_id IS NULL AND NEW.step IS DISTINCT FROM OLD.step THEN
                    RAISE EXCEPTION 'A signed or declined signing request cannot be changed (ADR-046).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER crm_signing_requests_protect_envelope BEFORE UPDATE ON crm_signing_requests
                FOR EACH ROW EXECUTE FUNCTION crm_signing_requests_protect_envelope();

            -- At most ten signers per envelope (owner decision 7); the action checks it too, under the envelope's lock.
            CREATE OR REPLACE FUNCTION crm_signing_requests_envelope_limit() RETURNS trigger AS $$
            BEGIN
                IF NEW.envelope_id IS NOT NULL AND (TG_OP = 'INSERT' OR OLD.envelope_id IS DISTINCT FROM NEW.envelope_id) THEN
                    IF (SELECT count(*) FROM crm_signing_requests WHERE envelope_id = NEW.envelope_id AND company_id = NEW.company_id) >= 10 THEN
                        RAISE EXCEPTION 'An envelope has at most ten signers (ADR-046 S1).';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER crm_signing_requests_envelope_limit BEFORE INSERT OR UPDATE OF envelope_id ON crm_signing_requests
                FOR EACH ROW EXECUTE FUNCTION crm_signing_requests_envelope_limit();

            -- At most ten copy recipients per envelope.
            CREATE OR REPLACE FUNCTION signing_copy_recipients_limit() RETURNS trigger AS $$
            BEGIN
                IF (SELECT count(*) FROM signing_copy_recipients WHERE envelope_id = NEW.envelope_id AND company_id = NEW.company_id) >= 10 THEN
                    RAISE EXCEPTION 'An envelope has at most ten copy recipients (ADR-046 S1).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_copy_recipients_limit BEFORE INSERT ON signing_copy_recipients
                FOR EACH ROW EXECUTE FUNCTION signing_copy_recipients_limit();

            -- A copy recipient's address never changes; the two times are set once.
            CREATE OR REPLACE FUNCTION signing_copy_recipients_protect() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.envelope_id IS DISTINCT FROM OLD.envelope_id
                    OR NEW.email IS DISTINCT FROM OLD.email
                    OR NEW.name IS DISTINCT FROM OLD.name
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (OLD.notified_at IS NOT NULL AND NEW.notified_at IS DISTINCT FROM OLD.notified_at)
                    OR (OLD.sent_copy_at IS NOT NULL AND NEW.sent_copy_at IS DISTINCT FROM OLD.sent_copy_at)
                THEN
                    RAISE EXCEPTION 'A copy recipient cannot be changed (ADR-046 S1).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_copy_recipients_protect BEFORE UPDATE ON signing_copy_recipients
                FOR EACH ROW EXECUTE FUNCTION signing_copy_recipients_protect();

            -- A link token never changes; it can only be revoked, once.
            CREATE OR REPLACE FUNCTION signing_link_tokens_protect() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.signing_request_id IS DISTINCT FROM OLD.signing_request_id
                    OR NEW.token_hash IS DISTINCT FROM OLD.token_hash
                    OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
                    OR NEW.reason IS DISTINCT FROM OLD.reason
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at)
                THEN
                    RAISE EXCEPTION 'A signing link token cannot be changed (ADR-046 S1).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_link_tokens_protect BEFORE UPDATE ON signing_link_tokens
                FOR EACH ROW EXECUTE FUNCTION signing_link_tokens_protect();

            -- What an envelope sent never changes; a finished envelope (completed, declined, voided) never changes
            -- except the follow-up time, set once.
            CREATE OR REPLACE FUNCTION signing_envelopes_protect() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.document_type IS DISTINCT FROM OLD.document_type
                    OR NEW.sales_document_id IS DISTINCT FROM OLD.sales_document_id
                    OR NEW.agreement_id IS DISTINCT FROM OLD.agreement_id
                    OR NEW.signable_id IS DISTINCT FROM OLD.signable_id
                    OR NEW.document_path IS DISTINCT FROM OLD.document_path
                    OR NEW.document_name IS DISTINCT FROM OLD.document_name
                    OR NEW.document_sha256 IS DISTINCT FROM OLD.document_sha256
                    OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (OLD.sent_at IS NOT NULL AND NEW.sent_at IS DISTINCT FROM OLD.sent_at)
                THEN
                    RAISE EXCEPTION 'An envelope''s document cannot be changed (ADR-046 S1).';
                END IF;

                IF OLD.status IN ('COMPLETED', 'DECLINED', 'VOIDED') THEN
                    IF NEW.status IS DISTINCT FROM OLD.status
                        OR NEW.title IS DISTINCT FROM OLD.title
                        OR NEW.current_step IS DISTINCT FROM OLD.current_step
                        OR NEW.deadline_at IS DISTINCT FROM OLD.deadline_at
                        OR NEW.completed_at IS DISTINCT FROM OLD.completed_at
                        OR NEW.declined_at IS DISTINCT FROM OLD.declined_at
                        OR NEW.voided_at IS DISTINCT FROM OLD.voided_at
                        OR (NEW.created_by IS NOT NULL AND NEW.created_by IS DISTINCT FROM OLD.created_by)
                        OR (NEW.voided_by IS NOT NULL AND NEW.voided_by IS DISTINCT FROM OLD.voided_by)
                        OR (OLD.followed_up_at IS NOT NULL AND NEW.followed_up_at IS DISTINCT FROM OLD.followed_up_at)
                    THEN
                        RAISE EXCEPTION 'A finished envelope cannot be changed (ADR-046 S1).';
                    END IF;
                END IF;

                -- Nothing goes back to a draft, and an envelope is completed only when every signer has signed.
                IF NEW.status = 'DRAFT' AND OLD.status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'An envelope cannot go back to a draft (ADR-046 S1).';
                END IF;

                IF NEW.status = 'COMPLETED' AND OLD.status <> 'COMPLETED'
                    AND EXISTS (SELECT 1 FROM crm_signing_requests WHERE envelope_id = NEW.id AND status <> 'SIGNED') THEN
                    RAISE EXCEPTION 'An envelope is completed only when every signer has signed (ADR-046 S1).';
                END IF;

                IF OLD.status <> 'DRAFT' AND NEW.current_step < OLD.current_step THEN
                    RAISE EXCEPTION 'An envelope''s step cannot go back (ADR-046 S1).';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_envelopes_protect BEFORE UPDATE ON signing_envelopes
                FOR EACH ROW EXECUTE FUNCTION signing_envelopes_protect();

            -- A completed envelope is evidence: never deleted, except by the cascade from deleting the company itself.
            CREATE OR REPLACE FUNCTION signing_envelopes_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1 AND NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                    RETURN OLD;
                END IF;

                IF OLD.status = 'COMPLETED' THEN
                    RAISE EXCEPTION 'completed signing envelope % cannot be deleted', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_envelopes_protect_delete BEFORE DELETE ON signing_envelopes
                FOR EACH ROW EXECUTE FUNCTION signing_envelopes_protect_delete();

            -- Who got the copy of a completed envelope stays, except by the cascade from deleting the company itself.
            CREATE OR REPLACE FUNCTION signing_copy_recipients_protect_delete() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF pg_trigger_depth() > 1 AND NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) THEN
                    RETURN OLD;
                END IF;

                IF EXISTS (SELECT 1 FROM signing_envelopes WHERE id = OLD.envelope_id AND status = 'COMPLETED') THEN
                    RAISE EXCEPTION 'a copy recipient of completed signing envelope % cannot be deleted', OLD.envelope_id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_copy_recipients_protect_delete BEFORE DELETE ON signing_copy_recipients
                FOR EACH ROW EXECUTE FUNCTION signing_copy_recipients_protect_delete();
        SQL);
    }

    /**
     * The backfill: one envelope per request that has none, rows inserted
     * only. The request's own evidence is not touched; only its new
     * envelope_id is set, which the new trigger allows once from null.
     * Idempotent: a request that has an envelope is left alone.
     */
    public function backfill(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TEMPORARY TABLE signing_envelope_backfill AS
                SELECT id AS request_id, gen_random_uuid() AS envelope_id
                FROM crm_signing_requests
                WHERE envelope_id IS NULL;

            INSERT INTO signing_envelopes (
                id, company_id, document_type, sales_document_id, agreement_id, signable_id, status, current_step,
                document_path, document_name, document_sha256, content_hash, deadline_at, created_by, sent_at,
                completed_at, declined_at, voided_at, followed_up_at, created_at, updated_at
            )
            SELECT b.envelope_id, r.company_id, r.document_type, r.sales_document_id, r.agreement_id, r.signable_id,
                CASE r.status WHEN 'SIGNED' THEN 'COMPLETED' WHEN 'DECLINED' THEN 'DECLINED' WHEN 'REVOKED' THEN 'VOIDED' ELSE 'OUT' END,
                1, r.document_path, r.document_name, r.document_sha256, r.content_hash, r.expires_at, r.sent_by, r.created_at,
                r.signed_at, r.declined_at, r.revoked_at, r.followed_up_at, r.created_at, now()
            FROM signing_envelope_backfill b
            JOIN crm_signing_requests r ON r.id = b.request_id;

            UPDATE crm_signing_requests r
                SET envelope_id = b.envelope_id
                FROM signing_envelope_backfill b
                WHERE r.id = b.request_id AND r.envelope_id IS NULL;

            DROP TABLE signing_envelope_backfill;
        SQL);
    }
};
