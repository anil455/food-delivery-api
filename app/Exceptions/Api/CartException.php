<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

use App\Enums\ApiErrorCode;
use Illuminate\Http\Response;

/**
 * Expected cart failures. Each carries a code the Next.js client can switch on
 * without reading the message text.
 */
final class CartException extends ApiException
{
    public static function productUnavailable(string $message = 'This item is currently unavailable.'): self
    {
        return new self($message, ApiErrorCode::ProductUnavailable, Response::HTTP_CONFLICT);
    }

    public static function invalidAddon(string $message): self
    {
        return new self($message, ApiErrorCode::ValidationError, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function coupon(string $message): self
    {
        return new self($message, ApiErrorCode::CouponInvalid, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function empty(): self
    {
        return new self(
            'Your cart is empty.',
            ApiErrorCode::CartEmpty,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function minimumNotMet(string $minimum): self
    {
        return new self(
            "This restaurant has a minimum order of {$minimum}.",
            ApiErrorCode::MinOrderNotMet,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
