<?php

// Redis client tuning for MGCRM.
//
// The Redis *connections* (default / cache) live in config/database.php under
// the 'redis' key — that is Laravel's canonical location and where the queue,
// cache and session drivers resolve them. This file holds only the predis
// client tuning that MGCRM code may read via config('redis.*'); it does NOT
// redefine the connections (that would diverge from database.php).
//
// MGCRM ships predis/predis (REDIS_CLIENT=predis) so the app does not depend on
// the phpredis C extension being present in every PHP context (it is in the app
// image, but predis keeps composer-image / CI runs working too).

return [

    /*
    |--------------------------------------------------------------------------
    | Client
    |--------------------------------------------------------------------------
    */
    'client' => env('REDIS_CLIENT', 'predis'),

    /*
    |--------------------------------------------------------------------------
    | Predis client options
    |--------------------------------------------------------------------------
    |
    | Passed through to predis where the connection is constructed. Read-timeout
    | is generous so a long blocking pop on a quiet queue does not error.
    |
    */
    'predis_options' => [
        'connections' => [
            'tcp' => [
                'timeout' => (float) env('REDIS_TIMEOUT', 5.0),
                'read_write_timeout' => (float) env('REDIS_READ_WRITE_TIMEOUT', 0),
            ],
        ],
    ],

];
