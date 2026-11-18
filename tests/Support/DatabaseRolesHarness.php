<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Database\DatabaseGrants;
use App\Support\Database\DatabaseRoles;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs a test as the runtime role of ADR-079 §6.
 *
 * - When the test database user is a superuser (a developer's machine, CI): the
 *   real scripts create the roles, transfer every object to nonox_owner and
 *   apply the grants, all inside the test transaction, and the test continues
 *   with SET LOCAL ROLE nonox_app. Everything is rolled back with the test:
 *   roles, owners and grants included (PostgreSQL DDL and role changes are
 *   transactional).
 * - When the suite already logs in as a role that cannot alter the ledger
 *   (DB_USERNAME=nonox_app against a database switched over to the roles),
 *   the test runs as that role directly.
 * - Otherwise (an owner that is not a superuser) the test is skipped.
 */
final class DatabaseRolesHarness
{
    public static function superuser(): bool
    {
        return (bool) DB::scalar('select rolsuper from pg_roles where rolname = current_user');
    }

    /** Creates the roles, transfers ownership and applies the grants inside the test transaction. Needs a superuser. */
    public static function install(): void
    {
        // Role changes touch the cluster-wide catalogue. The advisory lock is per database, so it orders
        // the test processes on one test database only; processes on different databases of the same
        // cluster can deadlock on the roles themselves. Test-only: rerun.
        DB::statement('select pg_advisory_xact_lock(790079)');
        self::lockEveryTable();
        DB::unprepared((string) file_get_contents(base_path('database/roles/roles.sql')));

        // A database already switched over needs no ownership transfer.
        if (! self::ownerOwnsEverything()) {
            DB::unprepared((string) file_get_contents(base_path('database/roles/transfer-ownership.sql')));
        }

        DatabaseGrants::apply(DB::connection());
    }

    /**
     * Takes the ACCESS EXCLUSIVE lock that ALTER ... OWNER needs on every table, before the transaction
     * changes a single pg_class row.
     *
     * The suite leaves many dead rows, so autovacuum is often busy on a table here. VACUUM ends with an
     * in-place update of the table's pg_class row, and PostgreSQL (17.1+, 18) then waits for any open
     * transaction that has updated that row; the GRANT/REVOKE ON ALL TABLES of roles.sql and every
     * ALTER TABLE ... OWNER do. Autovacuum then held SHARE UPDATE EXCLUSIVE on a table while it waited for
     * the test transaction, which in turn waited for that table: a deadlock (seen on users, voucher_rows
     * and units). Locking first leaves autovacuum nothing of ours to wait for: the lock request only waits
     * until PostgreSQL cancels the autovacuum in its way (after deadlock_timeout).
     */
    private static function lockEveryTable(): void
    {
        DB::unprepared(<<<'SQL'
            DO $lock$
            DECLARE
                tables text;
            BEGIN
                SELECT string_agg(c.oid::regclass::text, ', ' ORDER BY c.relname) INTO tables
                FROM pg_class c
                WHERE c.relnamespace = current_schema()::regnamespace
                  AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
                  AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e');

                IF tables IS NOT NULL THEN
                    EXECUTE 'LOCK TABLE ' || tables || ' IN ACCESS EXCLUSIVE MODE';
                END IF;
            END
            $lock$;
            SQL);
    }

    private static function ownerOwnsEverything(): bool
    {
        return (int) DB::scalar(<<<'SQL'
            select count(*) from pg_class c
            where c.relnamespace = current_schema()::regnamespace and c.relkind in ('r', 'p', 'v', 'm', 'S')
              and c.relowner <> 'nonox_owner'::regrole
              and not exists (select 1 from pg_depend d where d.classid = 'pg_class'::regclass and d.objid = c.oid and d.deptype = 'e')
            SQL) === 0
            && (int) DB::scalar(<<<'SQL'
                select count(*) from pg_proc p
                where p.pronamespace = current_schema()::regnamespace and p.proowner <> 'nonox_owner'::regrole
                  and not exists (select 1 from pg_depend d where d.classid = 'pg_proc'::regclass and d.objid = p.oid and d.deptype = 'e')
                SQL) === 0;
    }

    /** Continue the test as nonox_app (or skip when that is impossible). */
    public static function becomeApp(): void
    {
        if (self::superuser()) {
            self::install();
            DB::statement('SET LOCAL ROLE '.DatabaseRoles::APP);

            return;
        }

        if (! DatabaseRoles::canAlterLedger(DB::connection())) {
            return;
        }

        test()->markTestSkipped('Needs a superuser to create the roles, or a login as nonox_app.');
    }

    /** Runs the callback in a savepoint and returns what it threw, so the test transaction stays usable. */
    public static function attempt(callable $callback): ?Throwable
    {
        try {
            DB::transaction(function () use ($callback): void {
                $callback();
            });
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }
}
