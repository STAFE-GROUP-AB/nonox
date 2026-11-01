<?php

declare(strict_types=1);

/*
 * Only the one-time code settings live here; everything else comes from the
 * framework defaults. Sign-in is passwordless (ADR-000).
 */

return [
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
