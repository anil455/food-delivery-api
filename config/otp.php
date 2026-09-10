<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Delivery channel
    |--------------------------------------------------------------------------
    | 'log' writes the code to the log and sends nothing — the local default.
    | 'array' discards it, for tests. Production uses a real provider.
    |
    | Note the ?: rather than an env() default. Laravel casts the literal string
    | "null" in a .env file to PHP null, so a null-ish value must fall back here
    | or it reaches the driver match as an empty string.
    */
    'driver' => env('OTP_DRIVER') ?: 'log',

    /*
    |--------------------------------------------------------------------------
    | Code generation
    |--------------------------------------------------------------------------
    */
    'length' => (int) env('OTP_LENGTH', 6),
    'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    */
    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
    'per_phone_hourly' => (int) env('OTP_PER_PHONE_HOURLY', 5),
    'per_phone_daily' => (int) env('OTP_PER_PHONE_DAILY', 10),
    'per_ip_hourly' => (int) env('OTP_PER_IP_HOURLY', 20),
    'per_ip_daily' => (int) env('OTP_PER_IP_DAILY', 50),
    'verify_per_ip_per_10min' => (int) env('OTP_VERIFY_PER_IP_10MIN', 10),

    /*
    |--------------------------------------------------------------------------
    | Development conveniences — DOUBLE GATED
    |--------------------------------------------------------------------------
    | Both of these are honoured only when the flag below is true AND the app is
    | running in the local environment. AppServiceProvider throws at boot if the
    | flag is enabled while APP_ENV=production, so a bad .env fails the deploy
    | instead of quietly leaking codes.
    */
    'expose_in_response' => (bool) env('OTP_EXPOSE_IN_RESPONSE', false),

    /*
    | Fixed codes for QA handsets: "+919999999999:123456,+919888888888:654321"
    */
    'test_numbers' => array_filter(
        array_map('trim', explode(',', (string) env('OTP_TEST_NUMBERS', '')))
    ),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'msg91' => [
            'auth_key' => env('MSG91_AUTH_KEY'),
            'template_id' => env('MSG91_TEMPLATE_ID'),
            'sender_id' => env('MSG91_SENDER_ID'),
        ],
        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],

    /*
    | Default region for parsing phone numbers entered without a country code.
    */
    'default_phone_region' => env('OTP_DEFAULT_PHONE_REGION', 'IN'),
];
