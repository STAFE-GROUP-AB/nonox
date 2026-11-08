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
        DB::unprepared((string) file_get_contents(base_path('database/roles/roles.sql')));
        DB::unprepared((string) file_get_contents(base_path('database/roles/transfer-ownership.sql')));
        DatabaseGrants::apply(DB::connection());
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
