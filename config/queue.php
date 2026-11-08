<?php

declare(strict_types=1);

/*
 * Only what NONOX adds to the framework's queue configuration. A demo's
 * jobs (ADR-077) wait in the demo database, so that nothing about a demo is
 * written to the main one; run a worker for them with `queue:work demo`. On
 * an installation whose queue is "sync" they run at once.
 */
return [
    'connections' => [
        'demo' => [
            'driver' => env('NONOX_DEMO_QUEUE', env('QUEUE_CONNECTION') === 'sync' ? 'sync' : 'database'),
            'connection' => 'demo',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 700,
            'after_commit' => false,
        ],
    ],
];
