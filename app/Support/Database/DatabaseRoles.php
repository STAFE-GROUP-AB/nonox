<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;

/**
 * Separate database roles (ADR-079 §6, build step 2).
 *
 * - `nonox_owner` owns every table, trigger and function and is used only by
 *   `php artisan migrate --database=pgsql_owner`.
 * - `nonox_app` is the runtime role: the grants in DatabaseGrants, nothing more.
 *   It cannot alter a table, drop or replace a trigger or its function, truncate,
 *   or set session_replication_role, so the immutability triggers hold against
 *   any code that runs inside NONOX.
 * - `nonox_readonly` reads, for reports and backups.
 *
 * Opt-in: on only when DB_MIGRATION_USERNAME is set. Without it everything runs
 * as one user, as before; `php artisan nonox:doctor` then warns.
 */
final class DatabaseRoles
{
    public const OWNER = 'nonox_owner';

    public const APP = 'nonox_app';

    public const READONLY = 'nonox_readonly';

    /** Commands that change the schema and therefore must run as the owner. */
    public const SCHEMA_COMMANDS = [
        'migrate',
        'migrate:fresh',
        'migrate:install',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'db:wipe',
    ];

    public static function enabled(): bool
    {
        return (bool) config('database.roles.enabled');
    }

    public static function migrationConnection(): string
    {
        return (string) config('database.roles.migration_connection', 'pgsql_owner');
    }

    /**
     * Extra options for an `Artisan::call('migrate', …)` so that it runs as the owner when roles are on.
     *
     * @return array<string, string>
     */
    public static function migrateOptions(): array
    {
        return self::enabled() ? ['--database' => self::migrationConnection()] : [];
    }

    /** The command line a person should type to migrate. */
    public static function migrateCommand(): string
    {
        return self::enabled()
            ? 'php artisan migrate --database='.self::migrationConnection()
            : 'php artisan migrate';
    }

    /**
     * Why a schema command may not run on the given connection, or null when it may.
     * With roles on, only the owner connection may change the schema.
     */
    public static function refusal(string $command, ?string $connection): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        if (! in_array($command, self::SCHEMA_COMMANDS, true)) {
            return null;
        }

        if ($connection === self::migrationConnection()) {
            return null;
        }

        $owner = self::migrationConnection();

        return "Databasrollerna är påslagna (DB_MIGRATION_USERNAME är satt), så `{$command}` får bara köras som ägarrollen. "
            ."Kör `php artisan {$command} --database={$owner}`. Appens roll kan inte ändra tabeller eller triggers (ADR-079).";
    }

    /** Whether the role of this connection can switch off or replace the ledger triggers (owner, member of the owner, or superuser). */
    public static function canAlterLedger(ConnectionInterface $connection): bool
    {
        return (bool) $connection->scalar(<<<'SQL'
            select (select rolsuper from pg_roles where rolname = current_user)
                or pg_has_role(current_user, (select relowner from pg_class where oid = 'vouchers'::regclass), 'USAGE')
            SQL);
    }
}
