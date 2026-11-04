<?php

declare(strict_types=1);

return [
    'serialization' => 'json',

    // Only over https on a production server, even when SESSION_SECURE_COOKIE is not set (security review 2026-11-04).
    'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'),
];
