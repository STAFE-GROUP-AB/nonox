<?php

declare(strict_types=1);

/*
 * Only the one-time code settings live here; everything else comes from the
 * framework defaults. Sign-in is passwordless (ADR-000).
 */

return [
    'login_codes' => [
        'length' => 6,
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
