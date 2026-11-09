<?php

declare(strict_types=1);

return [
    'serialization' => 'json',

    // Only over https on a production server, even when SESSION_SECURE_COOKIE is not set (security review 2026-11-04).
    'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'),

    /*
     * The customer portal's own session cookie (ADR-075 §2, §8): another
     * name, sent only to /kundportal, and 30 minutes idle. Ending a portal
     * session can never end the same browser's NONOX session, nor the
     * reverse. Switched per request by UsePortalSession.
     */
    'portal' => [
        'cookie' => env('PORTAL_SESSION_COOKIE', 'nonox_kundportal'),
        'path' => '/kundportal',
        'lifetime' => 30,
    ],
];
