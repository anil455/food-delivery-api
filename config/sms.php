<?php

declare(strict_types=1);

/*
| Transactional SMS, separate from OTP delivery.
|
| The two share provider credentials but not code paths: Indian providers route
| OTP through a dedicated template/DLT flow with its own endpoint, while order
| updates are ordinary transactional text. Keeping them apart means changing one
| cannot silently break the other.
*/

return [
    'driver' => env('SMS_DRIVER') ?: 'log',

    'enabled' => (bool) env('SMS_ENABLED', true),

    // Prefixed to every message so a recipient knows who is texting them.
    'sender_name' => env('SMS_SENDER_NAME', config('app.name')),

    'providers' => [
        'msg91' => [
            'auth_key' => env('MSG91_AUTH_KEY'),
            'sender_id' => env('MSG91_SENDER_ID'),
        ],
        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],
];
