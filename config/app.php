<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'NONOX Ekonomisystem'),

    /*
    | Major and minor version. The build number from nonox:build-info is
    | appended (0.9.117) and written into exported files, such as SIE #PROGRAM.
    | The stage is shown next to it while it is set.
    */

    'version' => '0.9',

    'stage' => env('NONOX_STAGE', 'Öppen beta'),

    /*
    | The public landing page at "/" for visitors who are not signed in. Turn
    | it off on an installation that only its own users visit; they then see
    | the sign-in page. Links left empty fall back to the GitHub repository,
    | or are left out of the page.
    */

    'landing' => [
        'enabled' => (bool) env('NONOX_LANDING_PAGE', true),
        // The installation is the service hosted by the company above: new companies accept its terms and data processing agreement.
        'hosted' => (bool) env('NONOX_HOSTED', false),
        'github_url' => env('NONOX_GITHUB_URL'),
        'github_repository' => env('NONOX_GITHUB_REPOSITORY', 'STAFE-GROUP-AB/nonox'),
        'migration_guide_url' => env('NONOX_MIGRATION_GUIDE_URL'),
        'docs_url' => env('NONOX_DOCS_URL'),
        'api_url' => env('NONOX_API_DOCS_URL'),
        'contact_url' => env('NONOX_CONTACT_URL'),
        'terms_url' => env('NONOX_TERMS_URL'),
        'privacy_url' => env('NONOX_PRIVACY_URL'),
        'company_name' => env('NONOX_COMPANY_NAME'),
        'org_number' => env('NONOX_COMPANY_ORG_NUMBER'),
        'privacy_email' => env('NONOX_PRIVACY_EMAIL'),
        'security_email' => env('NONOX_SECURITY_EMAIL'),
        'company_address' => env('NONOX_COMPANY_ADDRESS'),
    ],

    /*
    | The user manual at /manual (ADR-036): Markdown articles in this folder,
    | with avsnitt.yaml naming the sections. Shown whenever the public pages
    | are; the tests point it at a small fixture manual.
    */

    'manual' => [
        'path' => env('NONOX_MANUAL_PATH') ?: base_path('docs/manual/sv'),
    ],

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
