<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Long-term validation of sealed files (ADR-046 amendment, gap 5, package
 * S11). On signing_envelopes:
 *
 * - ltv_level: B_LT once validation data (DSS/VRI) is in the file, B_LTA once
 *   a document timestamp covers it; only on a SEALED envelope whose seal has a
 *   signature timestamp (B_T), and it only moves up (trigger);
 * - timestamp_valid_until: until when the newest timestamp in the file can be
 *   relied on (its TSA certificate's expiry, or an earlier configured end of
 *   its hash algorithm); changes only together with a later
 *   last_timestamp_at, or from NULL once (trigger);
 * - ltv_failure and ltv_attempted_at: the last attempt and why it did not
 *   succeed, for the envelope page and the nightly retry. Free to change.
 *
 * Each file S11 writes is a TIMESTAMPED row in signing_document_versions
 * (append-only since S2). Adding the columns fires none of the evidence
 * triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_envelopes', function (Blueprint $table) {
            $table->string('ltv_level', 6)->nullable();
            $table->timestampTz('timestamp_valid_until')->nullable();
            $table->string('ltv_failure', 30)->nullable();
            $table->timestampTz('ltv_attempted_at')->nullable();
            $table->index(['ltv_level', 'timestamp_valid_until']);
        });

        DB::statement("ALTER TABLE signing_envelopes ADD CONSTRAINT signing_envelopes_ltv_level_check CHECK (ltv_level IS NULL OR (ltv_level IN ('B_LT', 'B_LTA') AND seal_status = 'SEALED' AND seal_level = 'B_T'))");

        DB::unprepared(<<<'SQL'
            -- Long-term validation only moves up, and the newest timestamp's validity only moves with a newer timestamp.
            CREATE OR REPLACE FUNCTION signing_envelopes_protect_ltv() RETURNS trigger AS $$
            BEGIN
                IF NEW.ltv_level IS DISTINCT FROM OLD.ltv_level AND NOT COALESCE(
                    NEW.ltv_level IS NOT NULL AND (OLD.ltv_level IS NULL OR (OLD.ltv_level = 'B_LT' AND NEW.ltv_level = 'B_LTA')),
                    false
                ) THEN
                    RAISE EXCEPTION 'An envelope''s long-term validation only moves up (ADR-046 S11).';
                END IF;

                IF OLD.timestamp_valid_until IS NOT NULL
                    AND NEW.timestamp_valid_until IS DISTINCT FROM OLD.timestamp_valid_until
                    AND NOT COALESCE(NEW.timestamp_valid_until IS NOT NULL AND NEW.last_timestamp_at > OLD.last_timestamp_at, false)
                THEN
                    RAISE EXCEPTION 'The validity of an envelope''s timestamp changes only with a newer timestamp (ADR-046 S11).';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE TRIGGER signing_envelopes_protect_ltv BEFORE UPDATE ON signing_envelopes
                FOR EACH ROW EXECUTE FUNCTION signing_envelopes_protect_ltv();
        SQL);
    }
};
