<?php

declare(strict_types=1);
use App\Modules\Portal\Models\PortalIdentity;

/*
 * Only the one-time code settings live here; everything else comes from the
 * framework defaults. Sign-in is passwordless (ADR-000).
 */

return [
    /*
     * The customer portal's own guard (ADR-075 §2): portal contacts sign in as
     * PortalIdentity, never as users. Merged with the framework's 'web' guard
     * and 'users' provider: Laravel 13 merges the guards and providers keys of
     * config/auth.php with its own defaults (LoadConfiguration::mergeableOptions).
     */
    'guards' => [
        'portal' => [
            'driver' => 'session',
            'provider' => 'portal_identities',
        ],
    ],

    'providers' => [
        'portal_identities' => [
            'driver' => 'eloquent',
            'model' => PortalIdentity::class,
        ],
    ],

    'login_codes' => [
        // Eight digits: guessing stays hopeless without locking anyone out (security review 2026-11-01).
        'length' => 8,
        'ttl_minutes' => 10,
        'cooldown_seconds' => 60,
        'max_attempts' => 5,

        /*
         * Local only: fill in and verify the code automatically and prefill the
         * email field with app.admin_email. Ignored in every other environment.
         */
        'autofill' => (bool) env('OTP_AUTOFILL', false),
    ],

    'passkeys' => [
        /*
         * Local only (ADR-029): sign in without the passkey step, because
         * WebAuthn needs a secure origin and http://*.test is not one. Needs
         * APP_ENV=local and a local APP_URL as well; ignored everywhere else.
         * Follows OTP_AUTOFILL unless set on its own.
         */
        'skip_locally' => (bool) env('PASSKEYS_SKIP_LOCALLY', env('OTP_AUTOFILL', false)),
    ],
];
