<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Signer checks (ADR-046, amendment 2026-11-18, gap 3, package S3). Per
 * signer the sender chooses a check: none (the link alone, as before), a
 * six-digit code to the signer's e-mail, or an access code the sender gives
 * the signer another way.
 *
 * - crm_signing_requests gains verification (the enum), access_code_hash
 *   (Hash::make of the sender's code), verified_at, verification_failures and
 *   locked_at. A new trigger keeps verification and access_code_hash fixed
 *   from the start and every check column fixed once the signer has signed
 *   or declined. The existing evidence triggers are not touched.
 * - signing_verification_codes holds every e-mail code sent: only an
 *   HMAC-SHA256 with the app key over the request id and the code, its
 *   expiry, attempts and the time it passed. A code row is fixed except its
 *   attempts (only upwards) and verified_at (once, from null).
 *
 * Additive only: new columns, a new table, new triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_signing_requests', function (Blueprint $table) {
            // NONE, EMAIL_CODE, ACCESS_CODE (PROVIDER comes with gap 12).
            $table->string('verification', 20)->default('NONE');
            $table->string('access_code_hash')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->unsignedSmallInteger('verification_failures')->default(0);
            $table->timestampTz('locked_at')->nullable();
        });

        DB::statement("ALTER TABLE crm_signing_requests ADD CONSTRAINT crm_signing_requests_verification_check CHECK (verification IN ('NONE', 'EMAIL_CODE', 'ACCESS_CODE', 'PROVIDER'))");
        DB::statement("ALTER TABLE crm_signing_requests ADD CONSTRAINT crm_signing_requests_access_code_check CHECK ((verification = 'ACCESS_CODE') = (access_code_hash IS NOT NULL))");

        Schema::create('signing_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('signing_request_id')->constrained('crm_signing_requests')->cascadeOnDelete();
            // LINK: the signer at /signera; ON_SITE: an ÄTA signed on the field worker's phone (ADR-074 O4).
            $table->string('channel', 10)->default('LINK');
            $table->char('code_mac', 64);
            $table->timestampTz('sent_at');
            $table->timestampTz('expires_at');
            $table->unsignedSmallInteger('max_attempts');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('verified_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'signing_request_id', 'sent_at']);
        });

        DB::statement("ALTER TABLE signing_verification_codes ADD CONSTRAINT signing_verification_codes_channel_check CHECK (channel IN ('LINK', 'ON_SITE'))");
        DB::statement('ALTER TABLE signing_verification_codes ADD CONSTRAINT signing_verification_codes_attempts_check CHECK (attempts <= max_attempts)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION crm_signing_requests_protect_verification() RETURNS trigger AS $$
            BEGIN
                IF NEW.verification IS DISTINCT FROM OLD.verification OR NEW.access_code_hash IS DISTINCT FROM OLD.access_code_hash THEN
                    RAISE EXCEPTION 'A signer''s check cannot be changed after sending (ADR-046 S3).';
                END IF;
                IF OLD.status IN ('SIGNED', 'DECLINED') AND (
                    NEW.verified_at IS DISTINCT FROM OLD.verified_at
                    OR NEW.verification_failures IS DISTINCT FROM OLD.verification_failures
                    OR NEW.locked_at IS DISTINCT FROM OLD.locked_at
                ) THEN
                    RAISE EXCEPTION 'A signed or declined signing request cannot be changed (ADR-046).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER crm_signing_requests_protect_verification BEFORE UPDATE ON crm_signing_requests
                FOR EACH ROW EXECUTE FUNCTION crm_signing_requests_protect_verification();

            CREATE OR REPLACE FUNCTION signing_verification_codes_protect() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.signing_request_id IS DISTINCT FROM OLD.signing_request_id
                    OR NEW.channel IS DISTINCT FROM OLD.channel
                    OR NEW.code_mac IS DISTINCT FROM OLD.code_mac
                    OR NEW.sent_at IS DISTINCT FROM OLD.sent_at
                    OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                    OR NEW.max_attempts IS DISTINCT FROM OLD.max_attempts
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'A signing code cannot be changed (ADR-046 S3).';
                END IF;
                IF NEW.attempts < OLD.attempts THEN
                    RAISE EXCEPTION 'A signing code''s attempts never go down (ADR-046 S3).';
                END IF;
                IF OLD.verified_at IS NOT NULL AND (NEW.verified_at IS DISTINCT FROM OLD.verified_at OR NEW.attempts IS DISTINCT FROM OLD.attempts) THEN
                    RAISE EXCEPTION 'A used signing code cannot be changed (ADR-046 S3).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_verification_codes_protect BEFORE UPDATE ON signing_verification_codes
                FOR EACH ROW EXECUTE FUNCTION signing_verification_codes_protect();
        SQL);
    }
};
