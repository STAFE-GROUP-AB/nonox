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
        DB::unprepared((string) file_get_contents(base_path('database/roles/roles.sql')));

        // A database already switched over needs no ownership transfer, and skipping it avoids locking every table.
        if (! self::ownerOwnsEverything()) {
            DB::unprepared((string) file_get_contents(base_path('database/roles/transfer-ownership.sql')));
        }

        DatabaseGrants::apply(DB::connection());
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
