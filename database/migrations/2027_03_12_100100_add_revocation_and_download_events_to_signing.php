<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Download links of the completion mail (ADR-046 S13), second part: a link
 * can be closed by the sender (revoked_at, set once from NULL), and every
 * download is a row with its time (no IP address, no browser). The trigger
 * now guards every column but the download counter (which only grows) and
 * revoked_at (NULL to a value, once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_download_links', function (Blueprint $table) {
            $table->timestampTz('revoked_at')->nullable();
        });

        Schema::create('signing_download_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('download_link_id')->constrained('signing_download_links')->cascadeOnDelete();
            $table->unsignedSmallInteger('file_index');
            $table->timestampTz('downloaded_at');

            $table->index(['company_id', 'download_link_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION signing_download_links_protect() RETURNS trigger AS $$
            BEGIN
                IF NEW.token_hash IS DISTINCT FROM OLD.token_hash
                    OR NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.envelope_id IS DISTINCT FROM OLD.envelope_id
                    OR NEW.signing_request_id IS DISTINCT FROM OLD.signing_request_id
                    OR NEW.kind IS DISTINCT FROM OLD.kind
                    OR NEW.email IS DISTINCT FROM OLD.email
                    OR NEW.language IS DISTINCT FROM OLD.language
                    OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'A signing download link cannot be changed (ADR-046 S13).';
                END IF;
                IF NEW.downloads < OLD.downloads THEN
                    RAISE EXCEPTION 'The download counter of a signing download link only grows (ADR-046 S13).';
                END IF;
                IF OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at THEN
                    RAISE EXCEPTION 'A closed signing download link stays closed (ADR-046 S13).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION signing_download_events_protect() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'A download record cannot be changed (ADR-046 S13).';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_download_events_protect BEFORE UPDATE ON signing_download_events
                FOR EACH ROW EXECUTE FUNCTION signing_download_events_protect();

            -- A record goes only with its link (the cascade from the link, the envelope or the company): then the link row is already gone.
            CREATE OR REPLACE FUNCTION signing_download_events_protect_delete() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM signing_download_links WHERE id = OLD.download_link_id) THEN
                    RAISE EXCEPTION 'A download record cannot be deleted (ADR-046 S13).';
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_download_events_protect_delete BEFORE DELETE ON signing_download_events
                FOR EACH ROW EXECUTE FUNCTION signing_download_events_protect_delete();
        SQL);
    }
};
