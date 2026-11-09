<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Connection;

/**
 * Pins the search path of every trigger function in the schema to the schema
 * first and pg_temp last, so that a temporary table can never shadow the table
 * a trigger reads (migration 2026_12_31_100000). Runs after every migrate, so a
 * trigger function added later is pinned at once; TriggerSearchPathTest stays
 * the guard. Idempotent; changes no function body and no data.
 */
final class TriggerFunctions
{
    public static function pinSql(): string
    {
        return <<<'SQL'
            DO $pin$
            DECLARE
                fn regprocedure;
            BEGIN
                FOR fn IN
                    SELECT p.oid::regprocedure FROM pg_proc p
                    WHERE p.pronamespace = current_schema()::regnamespace
                      AND p.prorettype = 'trigger'::regtype
                      AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e')
                      AND p.proconfig IS DISTINCT FROM ARRAY[format('search_path=%s, pg_temp', current_schema())]
                LOOP
                    EXECUTE format('ALTER FUNCTION %s SET search_path = %I, pg_temp', fn, current_schema());
                END LOOP;
            END
            $pin$;
            SQL;
    }

    /** Pins every trigger function on the connection, which must own them (the owner, or the single user). */
    public static function pin(Connection $connection): void
    {
        $connection->getPdo()->exec(self::pinSql());
    }
}
