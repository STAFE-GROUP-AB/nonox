<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The e-mail code before the files of a confidential envelope are downloaded
 * through a completion link (ADR-046 S13 amendment, owner decision
 * 2026-11-19). One row per code sent to the link's own address: only an
 * HMAC-SHA256 with the app key over the link id and the code is kept, the
 * expiry, the attempts and the time it passed. The row goes with its link
 * (cascade) and is pruned once it has expired.
 *
 * Additive only: a new table and a trigger that keeps a row fixed except its
 * attempts (only upwards) and verified_at (once, from null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_download_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('download_link_id')->constrained('signing_download_links')->cascadeOnDelete();
            $table->char('code_mac', 64);
            $table->timestampTz('sent_at');
            $table->timestampTz('expires_at');
            $table->unsignedSmallInteger('max_attempts');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('verified_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'download_link_id', 'sent_at']);
        });

        DB::statement('ALTER TABLE signing_download_codes ADD CONSTRAINT signing_download_codes_attempts_check CHECK (attempts <= max_attempts)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION signing_download_codes_protect() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.download_link_id IS DISTINCT FROM OLD.download_link_id
                    OR NEW.code_mac IS DISTINCT FROM OLD.code_mac
                    OR NEW.sent_at IS DISTINCT FROM OLD.sent_at
                    OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                    OR NEW.max_attempts IS DISTINCT FROM OLD.max_attempts THEN
                    RAISE EXCEPTION 'A download code cannot be changed (ADR-046 S13).';
                END IF;
                IF NEW.attempts < OLD.attempts THEN
                    RAISE EXCEPTION 'The attempts of a download code only grow (ADR-046 S13).';
                END IF;
                IF OLD.verified_at IS NOT NULL AND NEW.verified_at IS DISTINCT FROM OLD.verified_at THEN
                    RAISE EXCEPTION 'A used download code stays used (ADR-046 S13).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_download_codes_protect BEFORE UPDATE ON signing_download_codes
                FOR EACH ROW EXECUTE FUNCTION signing_download_codes_protect();
        SQL);
    }
};
