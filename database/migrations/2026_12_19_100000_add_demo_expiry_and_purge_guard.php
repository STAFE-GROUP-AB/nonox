<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Try without registering (ADR-077). A visitor gets a guest user and a demo
 * company of their own; both carry demo_expires_at, and a scheduled command
 * removes them when it has passed. That is the only deletion NONOX makes
 * and it only ever reaches rows that belong to an expired demo company.
 *
 * The posted-voucher triggers (AGENTS.md §1.2) reject every DELETE. Two things
 * make the purge possible without weakening them for anything else:
 *
 *  1. nonox_demo_purge(row) is true only while the transaction has named the
 *     row's company in the setting nonox.demo_purge, and the company is
 *     either already gone (mid-cascade) or flagged as an expired demo; or the
 *     row belongs to no company and names the expired guest in the setting
 *     nonox.demo_purge_user.
 *  2. Each protective function that guards DELETE gets one line at its top
 *     that lets such a row through. UPDATE, INSERT and TRUNCATE keep their
 *     rules, and no other row can be deleted: a real company is never
 *     flagged, so the function answers false for every row of it.
 *
 * A trigger function redefined by a later migration loses the line. The
 * test CreateDemoTest builds a whole demo company and purges it, so that is
 * caught the day it happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->timestamp('demo_expires_at')->nullable()->index();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('demo_expires_at')->nullable()->index();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION nonox_demo_purge(row_data jsonb) RETURNS boolean
            LANGUAGE plpgsql STABLE AS $$
            DECLARE
                purging text := current_setting('nonox.demo_purge', true);
                purging_user text := current_setting('nonox.demo_purge_user', true);
                row_company text := row_data ->> 'company_id';
            BEGIN
                -- A row of no company (an audit entry of the guest's own) goes with the expired guest it names.
                IF row_company IS NULL THEN
                    RETURN purging_user IS NOT NULL AND purging_user <> ''
                        AND row_data ->> 'user_id' = purging_user
                        AND EXISTS (SELECT 1 FROM users WHERE id = purging_user::bigint AND demo_expires_at IS NOT NULL AND demo_expires_at < now());
                END IF;

                IF purging IS NULL OR purging = '' OR row_company <> purging THEN
                    RETURN false;
                END IF;

                RETURN NOT EXISTS (SELECT 1 FROM teams WHERE id = purging::bigint)
                    OR EXISTS (SELECT 1 FROM teams WHERE id = purging::bigint AND demo_expires_at IS NOT NULL AND demo_expires_at < now());
            END;
            $$;

            CREATE OR REPLACE FUNCTION teams_demo_purge_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.demo_expires_at IS NULL OR OLD.demo_expires_at >= now() THEN
                    RAISE EXCEPTION 'company % is not an expired demo company and cannot be deleted', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF current_setting('nonox.demo_purge', true) IS DISTINCT FROM OLD.id::text THEN
                    RAISE EXCEPTION 'company % can only be deleted by the demo purge', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN OLD;
            END;
            $$;

            DROP TRIGGER IF EXISTS teams_demo_purge_guard ON teams;
            CREATE TRIGGER teams_demo_purge_guard
                BEFORE DELETE ON teams
                FOR EACH ROW EXECUTE FUNCTION teams_demo_purge_guard();
        SQL);

        $functions = DB::select(<<<'SQL'
            SELECT DISTINCT p.oid::regprocedure::text AS signature, pg_get_functiondef(p.oid) AS definition
            FROM pg_trigger t
            JOIN pg_proc p ON p.oid = t.tgfoid
            JOIN pg_class c ON c.oid = t.tgrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE NOT t.tgisinternal
              AND n.nspname = 'public'
              AND (t.tgtype & 1) <> 0
              AND (t.tgtype & 2) <> 0
              AND (t.tgtype & 8) <> 0
              AND p.proname <> 'teams_demo_purge_guard'
        SQL);

        foreach ($functions as $function) {
            if (str_contains($function->definition, 'nonox_demo_purge')) {
                continue;
            }

            $patched = preg_replace(
                '/\n([ \t]*)BEGIN[ \t]*\n/',
                "\n\$1BEGIN\n\$1    IF TG_OP = 'DELETE' AND nonox_demo_purge(to_jsonb(OLD)) THEN\n\$1        RETURN OLD;\n\$1    END IF;\n\n",
                $function->definition,
                1,
            );

            if (! is_string($patched) || $patched === $function->definition) {
                throw new RuntimeException("Could not add the demo purge exception to {$function->signature}.");
            }

            // @phpstan-ignore argument.type (the definition comes from the database's own catalog)
            DB::unprepared($patched);
        }
    }
};
