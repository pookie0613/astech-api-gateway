<?php

return [
    'host' => env('REDIS_HOST', 'localhost'),
    'port' => env('REDIS_PORT', 6379),
    'password' => env('REDIS_PASSWORD', null),
    'database' => env('REDIS_DB', 0),

    'queue_prefix' => env('REDIS_QUEUE_PREFIX', 'training_'),
    'channel_prefix' => env('REDIS_CHANNEL_PREFIX', 'training_'),

    'connection_timeout' => env('REDIS_CONNECTION_TIMOUT', 5.0),
    'read_write_timeout' => env('REDIS_READ_WRITE_TIMEOUT', 5.0),
];
