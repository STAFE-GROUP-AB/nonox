<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The completion mail to everyone (ADR-046 amendment 2026-11-18, gap 5,
 * package S13).
 *
 * language: each signer's and each copy recipient's language for their mails
 * (sv or en; Swedish for everything that existed). Additive columns with a
 * default; no trigger fires on adding them.
 *
 * signing_download_links: a finished file above the mail attachment limit
 * (15 MB) goes as a link instead. The link has its own token, valid 14 days (7 for some: see the second S13 migration);
 * only its SHA-256 is stored, so a copy of the database gives no working
 * link. A row is never changed except by counting downloads (trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_signing_requests', function (Blueprint $table) {
            $table->string('language', 2)->default('sv');
        });

        Schema::table('signing_copy_recipients', function (Blueprint $table) {
            $table->string('language', 2)->default('sv');
        });

        DB::statement("ALTER TABLE crm_signing_requests ADD CONSTRAINT crm_signing_requests_language_check CHECK (language IN ('sv', 'en'))");
        DB::statement("ALTER TABLE signing_copy_recipients ADD CONSTRAINT signing_copy_recipients_language_check CHECK (language IN ('sv', 'en'))");

        Schema::create('signing_download_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->uuid('envelope_id');
            $table->foreign('envelope_id')->references('id')->on('signing_envelopes')->cascadeOnDelete();
            $table->foreignId('signing_request_id')->nullable()->constrained('crm_signing_requests')->cascadeOnDelete();
            // SIGNER (a signer's own copy), COPY (copy recipients and other signers) or COMPANY.
            $table->string('kind', 10);
            $table->string('email');
            $table->string('language', 2)->default('sv');
            // SHA-256 of the 256-bit token; the token itself is only in the e-mail.
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'envelope_id']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE signing_download_links ADD CONSTRAINT signing_download_links_kind_check CHECK (kind IN ('SIGNER', 'COPY', 'COMPANY'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION signing_download_links_protect() RETURNS trigger AS $$
            BEGIN
                IF NEW.token_hash IS DISTINCT FROM OLD.token_hash
                    OR NEW.envelope_id IS DISTINCT FROM OLD.envelope_id
                    OR NEW.signing_request_id IS DISTINCT FROM OLD.signing_request_id
                    OR NEW.kind IS DISTINCT FROM OLD.kind
                    OR NEW.email IS DISTINCT FROM OLD.email
                    OR NEW.expires_at IS DISTINCT FROM OLD.expires_at THEN
                    RAISE EXCEPTION 'A signing download link cannot be changed (ADR-046 S13).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_download_links_protect BEFORE UPDATE ON signing_download_links
                FOR EACH ROW EXECUTE FUNCTION signing_download_links_protect();
        SQL);
    }
};
