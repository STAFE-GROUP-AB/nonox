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
];
