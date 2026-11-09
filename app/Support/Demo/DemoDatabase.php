<?php

declare(strict_types=1);

namespace App\Support\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The one database NONOX may empty: the demo database (ADR-077, addendum
 * 2026-11-08). Emptying means dropping its schema and building it again with
 * `migrate --database=demo`; no other database can be reached from here.
 *
 * Every operation first calls assertSafe(), which refuses unless the demo
 * connection's database is not the main database and its name ends in _demo,
 * both as configured and as the server reports it (current_database()).
 */
final class DemoDatabase
{
    public const string SUFFIX = '_demo';

    /** The advisory lock key (any constant): a start holds it shared, a rebuild exclusive. */
    public const int LOCK_KEY = 770077;

    /**
     * The demo database must be exactly "<main database>_demo" on the same host
     * and port: a database that merely ends in _demo, such as another site's on
     * a shared server, never qualifies.
     *
     * @throws RuntimeException when the demo connection is not a database that may be emptied
     */
    public static function assertSafe(): void
    {
        $mainConfig = (array) config('database.connections.'.DemoMode::mainConnection());
        $demoConfig = (array) config('database.connections.'.DemoMode::CONNECTION);
        $mainName = (string) ($mainConfig['database'] ?? '');

        // First what is configured, before any connection is made to a database that may be the wrong one...
        self::assertName((string) ($demoConfig['database'] ?? ''), $mainName);

        foreach (['host', 'port'] as $key) {
            if ((string) ($demoConfig[$key] ?? '') !== (string) ($mainConfig[$key] ?? '')) {
                throw new RuntimeException("Refusing: the demo connection's {$key} is not the main connection's.");
            }
        }

        // ...then what the servers say they are connected to.
        $query = 'select current_database() as name, inet_server_port() as port, inet_server_addr()::text as addr';
        $demo = DB::connection(DemoMode::CONNECTION)->selectOne($query);
        $main = DB::connection(DemoMode::mainConnection())->selectOne($query);

        self::assertName((string) $demo->name, (string) $main->name);

        if ($demo->port !== $main->port) {
            throw new RuntimeException('Refusing: the demo database is on another port than the main one.');
        }

        // The server's address; NULL (a unix socket) matches only NULL, so a socket and a network connection never match.
        if ($demo->addr !== $main->addr) {
            throw new RuntimeException('Refusing: the demo database is on another server address than the main one.');
        }
    }

    private static function assertName(string $name, string $mainName): void
    {
        if ($name === $mainName) {
            throw new RuntimeException("Refusing: the demo database \"{$name}\" is the main database.");
        }

        if ($name !== $mainName.self::SUFFIX) {
            throw new RuntimeException("Refusing: the demo database must be named exactly \"{$mainName}".self::SUFFIX."\", it is \"{$name}\" (does not end in ".self::SUFFIX.' after the main database\'s name).');
        }
    }

    /**
     * Runs $callback while no rebuild can run, and a rebuild cannot start
     * until it is done: a shared advisory lock on the demo database, taken
     * without waiting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function whileStarting(callable $callback): mixed
    {
        $demo = DB::connection(DemoMode::CONNECTION);

        // Never block a web worker: while a rebuild runs, a start is refused.
        if (! $demo->selectOne('select pg_try_advisory_lock_shared(?) as got', [self::LOCK_KEY])->got) {
            throw ValidationException::withMessages(['industry' => __('Demon städas just nu och är öppen igen om en stund. Försök igen senare.')]);
        }

        try {
            return $callback();
        } finally {
            $demo->selectOne('select pg_advisory_unlock_shared(?)', [self::LOCK_KEY]);
        }
    }

    public static function draining(): bool
    {
        return File::exists(self::drainFile());
    }

    public static function startDraining(): void
    {
        if (! self::draining()) {
            File::put(self::drainFile(), (string) now()->getTimestamp());
        }
    }

    public static function stopDraining(): void
    {
        File::delete(self::drainFile());
    }

    /** When the demo database was last built from scratch; the start of its life if it never was emptied. */
    public static function builtAt(): ?CarbonImmutable
    {
        $at = File::exists(self::builtFile()) ? (int) File::get(self::builtFile()) : null;

        return $at === null ? null : CarbonImmutable::createFromTimestamp($at);
    }

    public static function markBuilt(): void
    {
        File::put(self::builtFile(), (string) now()->getTimestamp());
    }

    /**
     * Drops the demo database's schema, builds it again and empties the demo's
     * own file directories (nothing else). Only call it when no demo is live.
     * Returns false, doing nothing, when a start holds the lock or when $stillDue,
     * asked again once the lock is held, says a demo has come to life meanwhile.
     *
     * @param  (callable(): bool)|null  $stillDue
     */
    public static function rebuild(?callable $stillDue = null): bool
    {
        self::assertSafe();

        $demo = DB::connection(DemoMode::CONNECTION);

        if (! $demo->selectOne('select pg_try_advisory_lock(?) as got', [self::LOCK_KEY])->got) {
            return false;
        }

        try {
            // A start may have finished between the caller's check and the lock: look again.
            if ($stillDue !== null && ! $stillDue()) {
                return false;
            }

            $demo->unprepared('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');

            $code = Artisan::call('migrate', ['--database' => DemoMode::CONNECTION, '--force' => true]);

            if ($code !== 0) {
                throw new RuntimeException('The demo database could not be built again: '.trim(Artisan::output()));
            }

            // The demo's files live under their own roots (DemoMode::enter), so deleting them cannot reach a main file.
            foreach (DemoMode::fileRoots() as $root) {
                File::deleteDirectory($root);
            }

            self::markBuilt();
        } finally {
            $demo->selectOne('select pg_advisory_unlock(?)', [self::LOCK_KEY]);
        }

        return true;
    }

    private static function drainFile(): string
    {
        return storage_path('framework/demo-draining');
    }

    private static function builtFile(): string
    {
        return storage_path('framework/demo-built-at');
    }
}
