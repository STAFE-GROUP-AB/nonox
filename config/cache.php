<?php

declare(strict_types=1);

/*
 * The encrypted chat (ADR-045) keeps everything in its own store and never in
 * the database: Redis when the server has it, otherwise files in a directory
 * of their own that `chat:prune` empties of expired entries. NONOX_CHAT_STORE
 * (redis, file or array) overrides the choice.
 */
$chatDriver = env('NONOX_CHAT_STORE');

if (! is_string($chatDriver) || $chatDriver === '') {
    $redisConfigured = env('REDIS_URL') !== null
        || env('REDIS_HOST') !== null
        || env('CACHE_STORE') === 'redis'
        || env('QUEUE_CONNECTION') === 'redis';

    $chatDriver = extension_loaded('redis') && $redisConfigured ? 'redis' : 'file';
}

return [
    'serializable_classes' => false,

    // Merged with the framework's stores (array, file, database, redis, ...).
    'stores' => [
        'chat' => match ($chatDriver) {
            'redis' => [
                'driver' => 'redis',
                'connection' => env('NONOX_CHAT_REDIS_CONNECTION', 'cache'),
                'lock_connection' => env('NONOX_CHAT_REDIS_LOCK_CONNECTION', 'default'),
            ],
            'array' => [
                'driver' => 'array',
                'serialize' => false,
            ],
            default => [
                'driver' => 'file',
                'path' => storage_path('framework/cache/chat'),
                'lock_path' => storage_path('framework/cache/chat'),
            ],
        },
    ],
];
