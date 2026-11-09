<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The staff ledger's append-only trigger let a DELETE through whenever the
 * transaction had set nonox.staff_ledger_prune = 'on'. Any database role can
 * set a custom setting, so the flag alone protected nothing. A DELETE now also
 * needs the entry to be past its retention period by the database's own clock:
 * kept through 31 December two years after the end of the calendar year in
 * which the fiscal year containing the entry ended (9 kap. 5 § tredje stycket
 * skatteförfarandeförordningen), checked for both the time of the entry and the
 * time it was received. Without a fiscal year covering the date the check
 * assumes the longest one the law allows, 18 months from the date (BFL 3 kap.
 * 3 §). That fallback is looser than PruneStaffLedger, which keeps an entry
 * whose fiscal year it cannot establish: the trigger is a floor against
 * deleting young entries, not a copy of the prune's rule. The fiscal years
 * themselves are writable by the app role, so a forged fiscal year could
 * shorten the floor by up to about a year (ADR-079, known limit).
 * TODO(legal-verify): same reading as PruneStaffLedger (AI-utredning 2026-11-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION staff_ledger_entries_kept_until(company bigint, at timestamp) RETURNS date
            LANGUAGE plpgsql STABLE
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                year_end date;
            BEGIN
                SELECT end_date INTO year_end FROM fiscal_years
                 WHERE company_id = company AND start_date <= at::date AND end_date >= at::date
                 ORDER BY end_date DESC LIMIT 1;

                IF year_end IS NULL THEN
                    year_end := (at::date + interval '18 months')::date;
                END IF;

                RETURN make_date(extract(year FROM year_end)::int + 2, 12, 31);
            END;
            $$;

            CREATE OR REPLACE FUNCTION staff_ledger_entries_append_only() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_temp
            AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                   AND current_setting('nonox.staff_ledger_prune', true) = 'on'
                   AND current_date > staff_ledger_entries_kept_until(OLD.company_id, OLD.occurred_at)
                   AND current_date > staff_ledger_entries_kept_until(OLD.company_id, OLD.received_at) THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'staff_ledger_entries is append-only: % is not allowed', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;
            SQL);
    }
};
