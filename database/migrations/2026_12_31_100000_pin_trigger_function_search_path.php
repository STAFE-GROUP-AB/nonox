<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Every trigger function looks up tables by unqualified name. A session can
 * create temporary tables, and the temporary schema is searched first unless
 * the search path names it, so a temporary table called vouchers or
 * voucher_rows used to shadow the real one inside the function: the rows of a
 * posted voucher could be changed, and an unbalanced voucher posted. Pinning
 * the search path to the schema first and pg_temp last closes that for every
 * trigger function, whatever role runs the statement.
 *
 * A trigger function added later must declare the same setting
 * (`SET search_path = public, pg_temp`); TriggerSearchPathTest fails otherwise.
 * The function bodies do not change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $pin$
            DECLARE
                fn regprocedure;
            BEGIN
                FOR fn IN
                    SELECT p.oid::regprocedure FROM pg_proc p
                    WHERE p.pronamespace = current_schema()::regnamespace
                      AND p.prorettype = 'trigger'::regtype
                      AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e')
                LOOP
                    EXECUTE format('ALTER FUNCTION %s SET search_path = %I, pg_temp', fn, current_schema());
                END LOOP;
            END
            $pin$;
            SQL);
    }
};
