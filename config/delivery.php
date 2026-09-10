<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    | All monetary columns are stored as integers in minor units. `minor_units`
    | is how many minor units make one major unit (100 paise = 1 rupee).
    */
    'currency' => env('APP_CURRENCY', 'INR'),
    'currency_minor_units' => (int) env('APP_CURRENCY_MINOR_UNITS', 100),

    /*
    |--------------------------------------------------------------------------
    | Platform defaults
    |--------------------------------------------------------------------------
    | Per-restaurant values on the restaurants table override these; they exist
    | so a newly created restaurant has sane figures before it is configured.
    */
    'default_delivery_radius_km' => (float) env('DELIVERY_DEFAULT_RADIUS_KM', 5),
    'default_prep_time_minutes' => (int) env('DELIVERY_DEFAULT_PREP_MINUTES', 25),
    'default_tax_percentage' => (float) env('DELIVERY_DEFAULT_TAX_PERCENT', 5),

    /*
    | Rough riding speed used to turn distance into an ETA. Deliberately
    | conservative — an under-promise is cheaper than an over-promise.
    */
    'average_speed_kmph' => (float) env('DELIVERY_AVERAGE_SPEED_KMPH', 18),
];
