<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Support\Files\StoredFiles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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

    /**
     * @throws RuntimeException when the demo connection is not a database that may be emptied
     */
    public static function assertSafe(): void
    {
        $mainName = (string) config('database.connections.'.DemoMode::mainConnection().'.database');

        // First what is configured, before any connection is made to a database that may be the wrong one...
        self::assertName((string) config('database.connections.'.DemoMode::CONNECTION.'.database'), $mainName);

        // ...then what the server says it is connected to, and what the main connection really is.
        $actual = (string) DB::connection(DemoMode::CONNECTION)->selectOne('select current_database() as name')->name;

        self::assertName($actual, $mainName);
        self::assertName($actual, (string) DB::connection(DemoMode::mainConnection())->getDatabaseName());
    }

    private static function assertName(string $name, string $mainName): void
    {
        if (! str_ends_with($name, self::SUFFIX)) {
            throw new RuntimeException("Refusing: the demo database \"{$name}\" does not end in ".self::SUFFIX.'.');
        }

        if ($name === $mainName) {
            throw new RuntimeException("Refusing: the demo database \"{$name}\" is the main database.");
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
     * Deletes the files the demo database refers to, drops its schema and
     * builds it again. Only call it when no demo is live.
     */
    public static function rebuild(): void
    {
        self::assertSafe();

        $paths = self::filePaths();

        $demo = DB::connection(DemoMode::CONNECTION);
        $demo->unprepared('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');
        $demo->disconnect();

        $code = Artisan::call('migrate', ['--database' => DemoMode::CONNECTION, '--force' => true]);

        if ($code !== 0) {
            throw new RuntimeException('The demo database could not be built again: '.trim(Artisan::output()));
        }

        // Only files no row of the main database refers to.
        $keep = StoredFiles::referenced($paths);
        Storage::disk(StoredFiles::DISK)->delete(array_values(array_diff($paths, $keep)));

        self::markBuilt();
    }

    /** @return list<string> every stored file a row in the demo database refers to, read before the schema is dropped */
    private static function filePaths(): array
    {
        $paths = [];
        $demo = DB::connection(DemoMode::CONNECTION);
        $schema = $demo->getSchemaBuilder();

        foreach (StoredFiles::REFERENCES as $table => $column) {
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $column)) {
                continue;
            }

            foreach ($demo->table($table)->whereNotNull($column)->pluck($column) as $path) {
                $paths[] = (string) $path;
            }
        }

        return array_values(array_unique($paths));
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
