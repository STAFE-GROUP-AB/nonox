<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'NONOX Ekonomisystem'),

    /*
    | The version written into exported files, such as SIE #PROGRAM.
    */

    'version' => '0.1.0',
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Stockholm'),
    'locale' => env('APP_LOCALE', 'sv'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'sv_SE'),

    /*
     * Optional. Locally the login form is prefilled with this address, or with
     * the first user's address when it is empty (ADR-000). Also used by the
     * developer demo seeder. Set it in .env, never in code.
     */
    'admin_email' => env('ADMIN_EMAIL'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],
];
