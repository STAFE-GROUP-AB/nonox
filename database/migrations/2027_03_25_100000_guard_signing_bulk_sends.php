<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Bulk send for signing (ADR-046 S15), the tenancy guards of the database:
 * a run's template and a row's run must belong to the same company as the row
 * that points at them, and a run's company never changes. Additive: two
 * triggers, no data touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION signing_bulk_sends_guard() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.company_id IS DISTINCT FROM OLD.company_id THEN
                    RAISE EXCEPTION 'A bulk send''s company cannot be changed (ADR-046 S15).';
                END IF;

                IF NEW.template_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM signing_templates WHERE id = NEW.template_id AND company_id = NEW.company_id) THEN
                    RAISE EXCEPTION 'A bulk send''s template must belong to the same company (ADR-046 S15).';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_bulk_sends_guard BEFORE INSERT OR UPDATE ON signing_bulk_sends
                FOR EACH ROW EXECUTE FUNCTION signing_bulk_sends_guard();

            CREATE OR REPLACE FUNCTION signing_bulk_send_rows_guard() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM signing_bulk_sends WHERE id = NEW.bulk_send_id AND company_id = NEW.company_id) THEN
                    RAISE EXCEPTION 'A bulk send row must belong to the same company as its run (ADR-046 S15).';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE TRIGGER signing_bulk_send_rows_guard BEFORE INSERT OR UPDATE ON signing_bulk_send_rows
                FOR EACH ROW EXECUTE FUNCTION signing_bulk_send_rows_guard();
        SQL);
    }
};
