<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The document archive's retention after review (ADR-081 E19):
 *
 * - The legal rule is the floor, not the earlier value. A document that is
 *   räkenskapsinformation (class ACCOUNTING, or a contract marked as
 *   räkenskapsinformation, BFL 1 kap. 2 § första stycket 6) is kept at least
 *   until 31 December seven years after the end of its fiscal year; the
 *   trigger computes that floor from fiscal_years. While the old floor is in
 *   force the new row must keep a floor at least as late: no leaving the
 *   class, dropping the mark or moving to an earlier fiscal year, so the
 *   floor cannot be escaped in two steps.
 *   Other dates (a chosen keep-until, a contract's period) may be lowered
 *   to the rule by the app (GDPR art. 5.1 e).
 * - A contract can be marked as räkenskapsinformation; it then needs a
 *   fiscal year.
 * - A legal hold has a reason (SKATTEREVISION, TVIST, ANNAT).
 * - A personnel document's title is stored encrypted in title_encrypted;
 *   the plain title column stays empty for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('is_accounting_record')->default(false);
            $table->string('legal_hold_reason', 20)->nullable();
            $table->text('title_encrypted')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE documents ADD CONSTRAINT documents_accounting_record_check
                CHECK (NOT is_accounting_record OR (retention_class = 'CONTRACT' AND fiscal_year_id IS NOT NULL AND retain_until IS NOT NULL));
            ALTER TABLE documents ADD CONSTRAINT documents_legal_hold_reason_check
                CHECK (CASE WHEN legal_hold THEN legal_hold_reason IS NOT NULL AND legal_hold_reason IN ('SKATTEREVISION', 'TVIST', 'ANNAT') ELSE legal_hold_reason IS NULL END);
            ALTER TABLE documents ADD CONSTRAINT documents_personnel_title_check
                CHECK (retention_class <> 'PERSONNEL' OR (title = '' AND title_encrypted IS NOT NULL));

            CREATE OR REPLACE FUNCTION documents_accounting_floor(doc documents) RETURNS date
            LANGUAGE sql STABLE
            SET search_path = public, pg_temp
            AS $$
                SELECT make_date(extract(year FROM fy.end_date)::int + 7, 12, 31)
                FROM fiscal_years fy
                WHERE fy.id = doc.fiscal_year_id
                  AND (doc.retention_class = 'ACCOUNTING' OR doc.is_accounting_record);
            $$;

            CREATE OR REPLACE FUNCTION documents_guard_retention() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                new_floor date;
                old_floor date;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    -- Only the cascade from a deleted company: its row in teams is already gone.
                    IF EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id) AND documents_retention_blocks(OLD) THEN
                        RAISE EXCEPTION 'The document is kept: legal hold, retention period or a running contract (ADR-081 E19).';
                    END IF;
                    RETURN OLD;
                END IF;

                IF TG_OP = 'UPDATE' AND NEW.company_id <> OLD.company_id THEN
                    RAISE EXCEPTION 'A document never moves to another company.';
                END IF;

                new_floor := documents_accounting_floor(NEW);

                IF new_floor IS NOT NULL AND (NEW.retain_until IS NULL OR NEW.retain_until < new_floor) THEN
                    RAISE EXCEPTION 'Räkenskapsinformation is kept until seven years after its fiscal year (ADR-081 E19).';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    old_floor := documents_accounting_floor(OLD);

                    -- While the old floor applies, the new state must keep a floor at least as late:
                    -- no leaving ACCOUNTING, no dropping the mark, no earlier fiscal year (two-step escape).
                    IF old_floor IS NOT NULL AND old_floor >= current_date
                       AND (new_floor IS NULL OR new_floor < old_floor
                            OR NEW.retain_until IS NULL OR NEW.retain_until < old_floor) THEN
                        RAISE EXCEPTION 'The retention date of räkenskapsinformation never moves below its floor (ADR-081 E19).';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            -- The UPDATE and DELETE trigger exists; the same function now also checks new rows.
            CREATE TRIGGER documents_guard_retention_insert BEFORE INSERT ON documents
                FOR EACH ROW EXECUTE FUNCTION documents_guard_retention();
        SQL);
    }
};
