<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Restaurant selection header
    |--------------------------------------------------------------------------
    | Staff clients name the restaurant they are acting on with this header.
    | The value is always validated against restaurant_users membership before
    | it becomes the tenant context — it is a hint, never an authorisation.
    */
    'restaurant_header' => env('TENANCY_RESTAURANT_HEADER', 'X-Restaurant-Id'),

    /*
    | Sanctum token abilities used as the coarse audience gate. Fine-grained
    | authorisation stays in policies.
    */
    'abilities' => [
        'customer' => 'customer',
        'restaurant' => 'restaurant',
        'admin' => 'admin',
    ],
];
