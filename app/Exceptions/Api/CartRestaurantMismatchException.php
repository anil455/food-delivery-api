<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

use App\Enums\ApiErrorCode;
use Illuminate\Http\Response;

/**
 * The customer tried to add an item from a restaurant other than the one their
 * cart already belongs to. Returns 409 with everything the client needs to show
 * a "clear your cart?" prompt — never silently mixes stores.
 */
final class CartRestaurantMismatchException extends ApiException
{
    public function __construct(
        int $currentRestaurantId,
        string $currentRestaurantName,
        int $requestedRestaurantId,
        string $requestedRestaurantName,
        int $cartItemCount,
    ) {
        parent::__construct(
            message: 'Your cart contains items from a different restaurant.',
            errorCode: ApiErrorCode::CartRestaurantMismatch,
            status: Response::HTTP_CONFLICT,
            payload: [
                'current_restaurant' => [
                    'id' => $currentRestaurantId,
                    'name' => $currentRestaurantName,
                ],
                'requested_restaurant' => [
                    'id' => $requestedRestaurantId,
                    'name' => $requestedRestaurantName,
                ],
                'cart_item_count' => $cartItemCount,
                'resolution' => 'Retry with replace_cart=true to clear the cart and continue.',
            ],
        );
    }
}
