<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The PAdES seal (ADR-046 amendment 2026-11-18, gap 5, package S10).
 *
 * signing_seal_certificates holds a company's own seal certificate as an
 * encrypted PKCS#12 with its encrypted password (the instance's self-signed
 * seal is a key file named in config, never a row). A certificate is
 * replaced, never edited: the trigger lets a row only be retired once, and
 * retiring empties its key and password, so a retired row keeps its public
 * facts (subject, issuer, serial, dates, fingerprint) and loses its key.
 * At most one active certificate per company.
 *
 * On the envelope: whether it is sealed (PENDING while the queue seals it,
 * SEALED or FAILED), the level reached, the sealed file and its SHA-256, the
 * certificate's fingerprint, and when; last_timestamp_at is for S11. Every
 * column is set once and only moves forward (trigger). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_seal_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('subject', 500);
            $table->string('issuer', 500);
            $table->string('serial', 100);
            $table->timestampTz('not_before');
            $table->timestampTz('not_after');
            $table->char('fingerprint_sha256', 64);
            $table->boolean('self_signed')->default(false);
            $table->text('pkcs12')->nullable();
            $table->text('password')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('expiry_task_id')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'retired_at']);
            $table->index(['company_id', 'not_after']);
        });

        DB::statement('CREATE UNIQUE INDEX signing_seal_certificates_one_active ON signing_seal_certificates (company_id) WHERE retired_at IS NULL');
        DB::statement('ALTER TABLE signing_seal_certificates ADD CONSTRAINT signing_seal_certificates_key_check CHECK ((retired_at IS NULL AND pkcs12 IS NOT NULL AND password IS NOT NULL) OR (retired_at IS NOT NULL AND pkcs12 IS NULL AND password IS NULL))');
        DB::statement('ALTER TABLE signing_seal_certificates ADD CONSTRAINT signing_seal_certificates_dates_check CHECK (not_after > not_before)');

        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->string('seal_status', 10)->nullable();
            $table->string('seal_level', 6)->nullable();
            $table->string('sealed_path')->nullable();
            $table->char('sealed_sha256', 64)->nullable();
            $table->char('seal_certificate_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('seal_certificate_id')->nullable();
            $table->timestampTz('sealed_at')->nullable();
            $table->timestampTz('last_timestamp_at')->nullable();
            $table->string('seal_failure', 30)->nullable();
        });

        DB::statement("ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_seal_status_check CHECK (seal_status IS NULL OR seal_status IN ('PENDING', 'SEALED', 'FAILED'))");
        DB::statement("ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_seal_level_check CHECK (seal_level IS NULL OR seal_level IN ('B_B', 'B_T', 'B_LT', 'B_LTA'))");
        DB::statement("ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_sealed_check CHECK ((seal_status = 'SEALED') = (sealed_path IS NOT NULL AND sealed_sha256 IS NOT NULL AND seal_level IS NOT NULL AND seal_certificate_fingerprint IS NOT NULL AND sealed_at IS NOT NULL))");
        DB::statement("ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_seal_completed_check CHECK (seal_status IS NULL OR status = 'COMPLETED')");

        DB::unprepared(<<<'SQL'
            -- A seal certificate is replaced, never edited: it may only be retired once, and retiring empties its key.
            CREATE OR REPLACE FUNCTION signing_seal_certificates_protect() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.subject IS DISTINCT FROM OLD.subject
                    OR NEW.issuer IS DISTINCT FROM OLD.issuer
                    OR NEW.serial IS DISTINCT FROM OLD.serial
                    OR NEW.not_before IS DISTINCT FROM OLD.not_before
                    OR NEW.not_after IS DISTINCT FROM OLD.not_after
                    OR NEW.fingerprint_sha256 IS DISTINCT FROM OLD.fingerprint_sha256
                    OR NEW.self_signed IS DISTINCT FROM OLD.self_signed
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (OLD.uploaded_by IS NOT NULL AND NEW.uploaded_by IS NOT NULL AND NEW.uploaded_by IS DISTINCT FROM OLD.uploaded_by)
                    OR (OLD.retired_at IS NOT NULL AND NEW.retired_at IS DISTINCT FROM OLD.retired_at)
                    OR (OLD.retired_by IS NOT NULL AND NEW.retired_by IS NOT NULL AND NEW.retired_by IS DISTINCT FROM OLD.retired_by)
                    OR (OLD.expiry_task_id IS NOT NULL AND NEW.expiry_task_id IS DISTINCT FROM OLD.expiry_task_id)
                    OR (NEW.pkcs12 IS NOT NULL AND NEW.pkcs12 IS DISTINCT FROM OLD.pkcs12)
                    OR (NEW.password IS NOT NULL AND NEW.password IS DISTINCT FROM OLD.password)
                THEN
                    RAISE EXCEPTION 'A seal certificate cannot be changed, only retired (ADR-046 S10).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_seal_certificates_protect BEFORE UPDATE ON signing_seal_certificates
                FOR EACH ROW EXECUTE FUNCTION signing_seal_certificates_protect();

            -- The seal of an envelope moves forward only: NULL -> PENDING -> SEALED or FAILED, each fact set once.
            CREATE OR REPLACE FUNCTION signing_envelopes_protect_seal() RETURNS trigger AS $$
            BEGIN
                IF NEW.seal_status IS DISTINCT FROM OLD.seal_status AND NOT (
                    (OLD.seal_status IS NULL AND NEW.seal_status = 'PENDING')
                    OR (OLD.seal_status = 'PENDING' AND NEW.seal_status IN ('SEALED', 'FAILED'))
                ) THEN
                    RAISE EXCEPTION 'An envelope''s seal only moves forward (ADR-046 S10).';
                END IF;

                IF (OLD.seal_level IS NOT NULL AND NEW.seal_level IS DISTINCT FROM OLD.seal_level)
                    OR (OLD.sealed_path IS NOT NULL AND NEW.sealed_path IS DISTINCT FROM OLD.sealed_path)
                    OR (OLD.sealed_sha256 IS NOT NULL AND NEW.sealed_sha256 IS DISTINCT FROM OLD.sealed_sha256)
                    OR (OLD.seal_certificate_fingerprint IS NOT NULL AND NEW.seal_certificate_fingerprint IS DISTINCT FROM OLD.seal_certificate_fingerprint)
                    OR (OLD.seal_certificate_id IS NOT NULL AND NEW.seal_certificate_id IS DISTINCT FROM OLD.seal_certificate_id)
                    OR (OLD.sealed_at IS NOT NULL AND NEW.sealed_at IS DISTINCT FROM OLD.sealed_at)
                    OR (OLD.seal_failure IS NOT NULL AND NEW.seal_failure IS DISTINCT FROM OLD.seal_failure)
                    OR (OLD.last_timestamp_at IS NOT NULL AND (NEW.last_timestamp_at IS NULL OR NEW.last_timestamp_at < OLD.last_timestamp_at))
                THEN
                    RAISE EXCEPTION 'An envelope''s seal cannot be changed (ADR-046 S10).';
                END IF;

                IF OLD.seal_status IN ('SEALED', 'FAILED') AND (
                    NEW.sealed_path IS DISTINCT FROM OLD.sealed_path
                    OR NEW.seal_failure IS DISTINCT FROM OLD.seal_failure
                    OR NEW.seal_certificate_id IS DISTINCT FROM OLD.seal_certificate_id
                ) THEN
                    RAISE EXCEPTION 'A finished seal cannot be changed (ADR-046 S10).';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_envelopes_protect_seal BEFORE UPDATE ON signing_envelopes
                FOR EACH ROW EXECUTE FUNCTION signing_envelopes_protect_seal();
        SQL);
    }
};
