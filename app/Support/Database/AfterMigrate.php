<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What runs after every migrate of the main database (AppServiceProvider):
 * every trigger function is pinned to a safe search path, and with the
 * database roles on the grant decisions are applied again, as the owner.
 * It also runs when a migrate fails half-way (`afterFailedMigrate`), so tables
 * a failed run did create still get their grants.
 */
final class AfterMigrate
{
    public static function run(): void
    {
        if (! DatabaseRoles::migratedMainDatabase()) {
            return;
        }

        $connection = DatabaseRoles::enabled()
            ? DB::connection(DatabaseRoles::migrationConnection())
            : DB::connection();

        TriggerFunctions::pin($connection);

        if (DatabaseRoles::enabled()) {
            DatabaseGrants::apply($connection);
        }
    }

    /** After a migrate that failed: the same, on the owner connection, never throwing over the original error. */
    public static function afterFailedMigrate(?string $connectionName): void
    {
        if (! DatabaseRoles::enabled() || $connectionName !== DatabaseRoles::migrationConnection()) {
            return;
        }

        try {
            $connection = DB::connection($connectionName);
            TriggerFunctions::pin($connection);
            DatabaseGrants::apply($connection);
        } catch (Throwable $exception) {
            Log::error('The grants could not be applied after a failed migrate; run php artisan nonox:db-grants.', ['exception' => $exception->getMessage()]);
        }
    }
}
