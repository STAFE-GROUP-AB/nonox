<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Confidential envelopes (ADR-046 amendment 2026-11-18, gap 10, package S7,
 * owner decision 20). A confidential envelope is visible only to the person
 * who sent it and to members with signing:manage; the choice is made when
 * sending and never changes, so an envelope cannot be hidden or shown
 * afterwards. Additive only: a new column and a new trigger; the existing
 * evidence triggers are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->boolean('confidential')->default(false);
        });

        // Only a standalone document can be confidential (ADR-046 S7); the engine checks it too, this is the last line.
        DB::statement("ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_confidential_check CHECK (NOT confidential OR document_type = 'DOCUMENT')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION signing_envelopes_protect_confidential() RETURNS trigger AS $$
            BEGIN
                IF NEW.confidential IS DISTINCT FROM OLD.confidential THEN
                    RAISE EXCEPTION 'An envelope''s confidentiality cannot be changed after sending (ADR-046 S7).';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_envelopes_protect_confidential BEFORE UPDATE ON signing_envelopes
                FOR EACH ROW EXECUTE FUNCTION signing_envelopes_protect_confidential();
        SQL);
    }
};
