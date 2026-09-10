<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Everything the client needs to configure itself, in one call on launch.
 *
 * Keeping currency rules, search defaults and the status vocabulary here means
 * the frontend never hardcodes a value the backend can change.
 */
class ConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(
            data: [
                'currency' => [
                    'code' => config('delivery.currency'),
                    // How many minor units make one major unit. Every money
                    // field in this API is exposed with both.
                    'minor_units' => config('delivery.currency_minor_units'),
                ],

                'search' => [
                    'default_radius_km' => (float) config('geo.default_search_radius_km'),
                    'max_radius_km' => (float) config('geo.max_search_radius_km'),
                    'result_limit' => (int) config('geo.result_limit'),
                ],

                'otp' => [
                    'length' => (int) config('otp.length'),
                    'ttl_seconds' => (int) config('otp.ttl_seconds'),
                    'resend_after_seconds' => (int) config('otp.resend_cooldown_seconds'),
                    'max_attempts' => (int) config('otp.max_attempts'),
                ],

                // Lets the client render a status timeline without duplicating
                // the state machine that lives in the OrderStatus enum.
                'order_statuses' => array_map(
                    static fn (OrderStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                        'is_terminal' => $status->isTerminal(),
                    ],
                    OrderStatus::cases(),
                ),

                'payment_methods' => [
                    ['value' => 'cod', 'label' => 'Cash on delivery', 'enabled' => true],
                    ['value' => 'online', 'label' => 'Pay online', 'enabled' => false],
                ],
            ],
            message: 'Configuration fetched successfully',
        );
    }
}
