<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Consent to electronic records (ADR-046, amendment 2026-11-18, gap 7,
 * package S4). Before the document is shown, each signer accepts a short,
 * versioned text (App\Domain\Signing\SigningConsent). The signer row keeps
 * which version, the SHA-256 of the exact text shown, and when.
 *
 * - The three columns are set together or not at all (check constraint).
 * - A new trigger lets them be set once, from null, while the signer is
 *   still pending, and never changed or cleared after that. The existing
 *   evidence triggers are not touched.
 *
 * Additive only: new nullable columns, a constraint, a new trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_signing_requests', function (Blueprint $table) {
            $table->string('consent_version', 20)->nullable();
            $table->char('consent_text_sha256', 64)->nullable();
            $table->timestampTz('consent_at')->nullable();
        });

        DB::statement('ALTER TABLE crm_signing_requests ADD CONSTRAINT crm_signing_requests_consent_check CHECK ((consent_version IS NULL) = (consent_at IS NULL) AND (consent_text_sha256 IS NULL) = (consent_at IS NULL))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION crm_signing_requests_protect_consent() RETURNS trigger AS $$
            BEGIN
                IF NEW.consent_version IS NOT DISTINCT FROM OLD.consent_version
                    AND NEW.consent_text_sha256 IS NOT DISTINCT FROM OLD.consent_text_sha256
                    AND NEW.consent_at IS NOT DISTINCT FROM OLD.consent_at
                THEN
                    RETURN NEW;
                END IF;
                IF OLD.consent_at IS NOT NULL THEN
                    RAISE EXCEPTION 'A signer''s consent to electronic records cannot be changed (ADR-046 S4).';
                END IF;
                IF OLD.status <> 'PENDING' THEN
                    RAISE EXCEPTION 'Consent to electronic records is given only while the signer is pending (ADR-046 S4).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER crm_signing_requests_protect_consent BEFORE UPDATE ON crm_signing_requests
                FOR EACH ROW EXECUTE FUNCTION crm_signing_requests_protect_consent();
        SQL);
    }
};
