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
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
