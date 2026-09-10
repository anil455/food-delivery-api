<?php

declare(strict_types=1);

/*
| An explicit allowlist, never a wildcard. FRONTEND_URL accepts a comma
| separated list so staging and production origins can share one deployment.
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))
)));

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-Restaurant-Id',
        'Idempotency-Key',
    ],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 3600,

    /*
    | False on purpose. Authentication is Bearer tokens, not cookies, so the
    | browser never needs to send credentials cross-origin. See deviation D7.
    */
    'supports_credentials' => false,
];
