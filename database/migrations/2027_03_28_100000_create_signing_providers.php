<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Signing providers (ADR-046 amendment 2026-11-18, gap 12, package S17).
 *
 * signing_providers is a company's configuration of an external signing
 * service (a CSC API v2 remote signing service; later BankID): the driver,
 * its base address, the OAuth client id and secret (encrypted by the model),
 * and the level the company states the provider gives under its agreement.
 * A configuration is replaced, never edited: the trigger lets a row only be
 * retired once, and retiring empties its client secret.
 *
 * signing_provider_sessions is one signer's run through a provider: the
 * OAuth state (only its hash is looked up), the encrypted working secrets
 * (PKCE verifier, tokens, the signer's form) and the file prepared for the
 * signature, all emptied when the run ends; and the public facts of the
 * signature it produced (certificate name, issuer, serial, fingerprint,
 * expiry, the stated level). A finished run is evidence: its facts are set
 * once, and it is deleted only with its signer (cascade).
 *
 * On crm_signing_requests: the provider a PROVIDER signer signs with, fixed
 * from the insert. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('driver', 20);
            $table->string('name', 120);
            $table->string('base_url', 500);
            $table->string('oauth_url', 500)->nullable();
            $table->text('client_id');
            $table->text('client_secret')->nullable();
            $table->string('claimed_level', 3);
            $table->string('service_name', 255)->nullable();
            $table->char('service_region', 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('retired_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['company_id', 'retired_at']);
        });

        DB::statement("ALTER TABLE signing_providers ADD CONSTRAINT signing_providers_driver_check CHECK (driver IN ('csc', 'fake'))");
        DB::statement("ALTER TABLE signing_providers ADD CONSTRAINT signing_providers_level_check CHECK (claimed_level IN ('SES', 'AES', 'QES'))");
        DB::statement('ALTER TABLE signing_providers ADD CONSTRAINT signing_providers_retired_check CHECK (retired_at IS NULL OR client_secret IS NULL)');

        Schema::create('signing_provider_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('signing_request_id')->constrained('crm_signing_requests')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('signing_providers');
            // SERVICE: at the provider for identification; CREDENTIAL: at the provider to authorise the hash; DONE; FAILED.
            $table->string('step', 12);
            $table->char('state_hash', 64)->nullable();
            $table->text('secrets')->nullable();
            $table->string('prepared_path')->nullable();
            $table->char('shown_sha256', 64);
            $table->char('digest_sha256', 64)->nullable();
            $table->string('certificate_name', 255)->nullable();
            $table->string('certificate_issuer', 255)->nullable();
            $table->string('certificate_serial', 100)->nullable();
            $table->char('certificate_sha256', 64)->nullable();
            $table->timestampTz('certificate_not_after')->nullable();
            $table->string('claimed_level', 3);
            $table->string('provider_name', 255);
            $table->unsignedBigInteger('signed_version_id')->nullable();
            $table->string('failure', 40)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'signing_request_id']);
            $table->index(['company_id', 'step', 'expires_at']);
        });

        DB::statement('CREATE UNIQUE INDEX signing_provider_sessions_state ON signing_provider_sessions (state_hash) WHERE state_hash IS NOT NULL');
        DB::statement("ALTER TABLE signing_provider_sessions ADD CONSTRAINT signing_provider_sessions_step_check CHECK (step IN ('SERVICE', 'CREDENTIAL', 'DONE', 'FAILED'))");
        DB::statement("ALTER TABLE signing_provider_sessions ADD CONSTRAINT signing_provider_sessions_level_check CHECK (claimed_level IN ('SES', 'AES', 'QES'))");
        DB::statement("ALTER TABLE signing_provider_sessions ADD CONSTRAINT signing_provider_sessions_done_check CHECK ((step = 'DONE') = (signed_version_id IS NOT NULL AND certificate_sha256 IS NOT NULL AND completed_at IS NOT NULL))");
        DB::statement("ALTER TABLE signing_provider_sessions ADD CONSTRAINT signing_provider_sessions_ended_check CHECK (step NOT IN ('DONE', 'FAILED') OR (state_hash IS NULL AND secrets IS NULL))");

        Schema::table('crm_signing_requests', function (Blueprint $table) {
            $table->foreignId('signing_provider_id')->nullable()->constrained('signing_providers');
        });

        DB::statement("ALTER TABLE crm_signing_requests ADD CONSTRAINT crm_signing_requests_provider_check CHECK ((verification = 'PROVIDER') = (signing_provider_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            -- A provider configuration is replaced, never edited: it may only be retired once, and retiring empties its secret.
            CREATE OR REPLACE FUNCTION signing_providers_protect() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.driver IS DISTINCT FROM OLD.driver
                    OR NEW.name IS DISTINCT FROM OLD.name
                    OR NEW.base_url IS DISTINCT FROM OLD.base_url
                    OR NEW.oauth_url IS DISTINCT FROM OLD.oauth_url
                    OR NEW.client_id IS DISTINCT FROM OLD.client_id
                    OR NEW.claimed_level IS DISTINCT FROM OLD.claimed_level
                    OR NEW.service_name IS DISTINCT FROM OLD.service_name
                    OR NEW.service_region IS DISTINCT FROM OLD.service_region
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (OLD.created_by IS NOT NULL AND NEW.created_by IS NOT NULL AND NEW.created_by IS DISTINCT FROM OLD.created_by)
                    OR (OLD.retired_at IS NOT NULL AND NEW.retired_at IS DISTINCT FROM OLD.retired_at)
                    OR (OLD.retired_by IS NOT NULL AND NEW.retired_by IS NOT NULL AND NEW.retired_by IS DISTINCT FROM OLD.retired_by)
                    OR (NEW.client_secret IS NOT NULL AND NEW.client_secret IS DISTINCT FROM OLD.client_secret)
                THEN
                    RAISE EXCEPTION 'A signing provider cannot be changed, only retired (ADR-046 S17).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_providers_protect BEFORE UPDATE ON signing_providers
                FOR EACH ROW EXECUTE FUNCTION signing_providers_protect();

            -- A run moves forward only; a finished run's facts never change, and it goes only with its signer.
            CREATE OR REPLACE FUNCTION signing_provider_sessions_protect() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.step = 'DONE' AND pg_trigger_depth() < 2 THEN
                        RAISE EXCEPTION 'A finished provider signature is deleted only with its signer (ADR-046 S17).';
                    END IF;
                    RETURN OLD;
                END IF;

                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.signing_request_id IS DISTINCT FROM OLD.signing_request_id
                    OR NEW.provider_id IS DISTINCT FROM OLD.provider_id
                    OR NEW.shown_sha256 IS DISTINCT FROM OLD.shown_sha256
                    OR NEW.claimed_level IS DISTINCT FROM OLD.claimed_level
                    OR NEW.provider_name IS DISTINCT FROM OLD.provider_name
                    OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'A provider signing run cannot be changed (ADR-046 S17).';
                END IF;

                IF NEW.step IS DISTINCT FROM OLD.step AND NOT (
                    (OLD.step = 'SERVICE' AND NEW.step IN ('CREDENTIAL', 'FAILED'))
                    OR (OLD.step = 'CREDENTIAL' AND NEW.step IN ('DONE', 'FAILED'))
                ) THEN
                    RAISE EXCEPTION 'A provider signing run only moves forward (ADR-046 S17).';
                END IF;

                IF (OLD.digest_sha256 IS NOT NULL AND NEW.digest_sha256 IS DISTINCT FROM OLD.digest_sha256)
                    OR (OLD.certificate_name IS NOT NULL AND NEW.certificate_name IS DISTINCT FROM OLD.certificate_name)
                    OR (OLD.certificate_issuer IS NOT NULL AND NEW.certificate_issuer IS DISTINCT FROM OLD.certificate_issuer)
                    OR (OLD.certificate_serial IS NOT NULL AND NEW.certificate_serial IS DISTINCT FROM OLD.certificate_serial)
                    OR (OLD.certificate_sha256 IS NOT NULL AND NEW.certificate_sha256 IS DISTINCT FROM OLD.certificate_sha256)
                    OR (OLD.certificate_not_after IS NOT NULL AND NEW.certificate_not_after IS DISTINCT FROM OLD.certificate_not_after)
                    OR (OLD.signed_version_id IS NOT NULL AND NEW.signed_version_id IS DISTINCT FROM OLD.signed_version_id)
                    OR (OLD.failure IS NOT NULL AND NEW.failure IS DISTINCT FROM OLD.failure)
                    OR (OLD.completed_at IS NOT NULL AND NEW.completed_at IS DISTINCT FROM OLD.completed_at)
                THEN
                    RAISE EXCEPTION 'A provider signing run''s facts are set once (ADR-046 S17).';
                END IF;

                IF OLD.step IN ('DONE', 'FAILED') AND (
                    NEW.state_hash IS NOT NULL
                    OR NEW.secrets IS NOT NULL
                    OR (NEW.prepared_path IS NOT NULL AND NEW.prepared_path IS DISTINCT FROM OLD.prepared_path)
                ) THEN
                    RAISE EXCEPTION 'A finished provider signing run can only be emptied (ADR-046 S17).';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_provider_sessions_protect BEFORE UPDATE OR DELETE ON signing_provider_sessions
                FOR EACH ROW EXECUTE FUNCTION signing_provider_sessions_protect();

            -- The provider a PROVIDER signer signs with is fixed from the insert, like the check itself (S3).
            CREATE OR REPLACE FUNCTION crm_signing_requests_protect_provider() RETURNS trigger AS $$
            BEGIN
                IF NEW.signing_provider_id IS DISTINCT FROM OLD.signing_provider_id THEN
                    RAISE EXCEPTION 'A signer''s provider cannot be changed after sending (ADR-046 S17).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER crm_signing_requests_protect_provider BEFORE UPDATE ON crm_signing_requests
                FOR EACH ROW EXECUTE FUNCTION crm_signing_requests_protect_provider();
        SQL);
    }
};
