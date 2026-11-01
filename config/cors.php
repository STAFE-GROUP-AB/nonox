<?php

declare(strict_types=1);

/*
 * CORS is off (ADR-032). Laravel's default answers every origin on api/*;
 * NONOX's API is called by servers and native apps with a token, never by
 * a browser on another site, so no path gets CORS headers.
 */
return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
