<?php

declare(strict_types=1);

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
    |--------------------------------------------------------------------------
    | What to document
    |--------------------------------------------------------------------------
    | Only the versioned API. Health checks and any future web routes stay out
    | of the published contract.
    */
    'api_path' => 'api/v1',

    'api_domain' => null,

    /*
    | Where the generated spec is written when `scramble:export` runs. Committing
    | it means the frontend can generate a typed client in CI without booting
    | this application.
    */
    'export_path' => 'api.json',

    'info' => [
        'version' => '1.0.0',
        'description' => <<<'MARKDOWN'
        REST API for a multi-restaurant food delivery platform.

        **Authentication.** Customers sign in with phone and OTP; staff with
        email and password. Both receive a Sanctum bearer token — send it as
        `Authorization: Bearer <token>`.

        **Tenancy.** Restaurant-admin routes require an `X-Restaurant-Id`
        header, which is validated against staff membership on every request.
        An id the caller has no membership for returns 403, never data.

        **Envelope.** Every response, success or failure, has the same shape:
        `{ success, message, data }`, with a machine-readable `code` on
        failures. Switch on `code`, never on `message`.

        **Money.** Every monetary value is returned as
        `{ amount: "522.40", minor: 52240, currency: "INR" }`. Render `amount`,
        compute with `minor`. Requests that carry money take integer minor units.

        **Idempotency.** Send a unique `Idempotency-Key` header on
        `POST /orders`. A retry with the same key returns the original order
        rather than creating a second one.
        MARKDOWN,
    ],

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    */
    'ui' => [
        'title' => 'Food Delivery API',
        'theme' => 'light',
        'hide_try_it' => false,
        'hide_schemas' => false,
        'logo' => '',
        'try_it_credentials_policy' => 'include',
        'layout' => 'responsive',
    ],

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    */
    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    | RestrictedDocsAccess limits the browsable docs to the local environment.
    | The generated api.json is safe to share; a live "try it" console against
    | production is not.
    */
    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [],
];
