<?php

declare(strict_types=1);

$pgsql = [
    'driver' => 'pgsql',
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'nonox'),
    'username' => env('DB_USERNAME', 'postgres'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8'),
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    // NONOX always runs in Europe/Stockholm (AGENTS.md §5). Timestamps are stored without a zone
    // and some queries use NOW(), date_trunc or AT TIME ZONE, so the session uses the same zone.
    'timezone' => 'Europe/Stockholm',
    'sslmode' => env('DB_SSLMODE', 'prefer'),
];

return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => [
        'pgsql' => $pgsql,
        /*
         * Try without registering (ADR-077): demos live in a database of their own, with the full schema
         * (php artisan migrate --database=demo, at deploy). Its name must end in _demo and differ from the
         * main database; demo:prune refuses to run otherwise. Nothing about a demo is written to the main one.
         */
        'demo' => [
            ...$pgsql,
            'url' => env('DB_DEMO_URL'),
            'database' => env('DB_DEMO_DATABASE', env('DB_DATABASE', 'nonox').'_demo'),
            'username' => env('DB_DEMO_USERNAME', env('DB_USERNAME', 'postgres')),
            'password' => env('DB_DEMO_PASSWORD', env('DB_PASSWORD', '')),
        ],
        // ADR-079 §6: the owner of every table, trigger and function. Used only by
        // `php artisan migrate --database=pgsql_owner`, never at runtime. Without
        // DB_MIGRATION_USERNAME it is the same user as `pgsql` (one role, as before).
        // With it, the owner's password is never taken from DB_PASSWORD and must not be in
        // .env or the cached config: the owner logs in by peer authentication as its own
        // Unix user (DB_MIGRATION_HOST = the socket directory), or the deploy passes
        // DB_MIGRATION_PASSWORD in the environment of the migrate command only
        // (docs/drift/databasroller.md). nonox:doctor warns when the running config has it.
        'pgsql_owner' => [
            'driver' => 'pgsql',
            'url' => env('DB_MIGRATION_URL', filled(env('DB_MIGRATION_USERNAME')) ? null : env('DB_URL')),
            'host' => env('DB_MIGRATION_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_MIGRATION_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_DATABASE', 'nonox'),
            'username' => env('DB_MIGRATION_USERNAME', env('DB_USERNAME', 'postgres')),
            'password' => filled(env('DB_MIGRATION_USERNAME')) ? env('DB_MIGRATION_PASSWORD', '') : env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'timezone' => 'Europe/Stockholm',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
    ],
    // ADR-079 §6: separate database roles. On when DB_MIGRATION_USERNAME is set: the
    // runtime connection (`pgsql`) then logs in as the app role, migrations must run on
    // `pgsql_owner`, and the grants are re-applied after every migrate. Off by default,
    // so development, CI and existing installs keep working with one user.
    'roles' => [
        'enabled' => filled(env('DB_MIGRATION_USERNAME')),
        'migration_connection' => 'pgsql_owner',
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
