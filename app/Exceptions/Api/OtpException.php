<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

use App\Enums\ApiErrorCode;
use Illuminate\Http\Response;

final class OtpException extends ApiException
{
    public static function invalid(): self
    {
        return new self(
            'The code you entered is incorrect.',
            ApiErrorCode::OtpInvalid,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function expired(): self
    {
        return new self(
            'This code has expired. Request a new one.',
            ApiErrorCode::OtpExpired,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function maxAttempts(): self
    {
        return new self(
            'Too many incorrect attempts. Request a new code.',
            ApiErrorCode::OtpMaxAttempts,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function notFound(): self
    {
        return new self(
            'This verification request is no longer valid. Request a new code.',
            ApiErrorCode::OtpNotFound,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function resendCooldown(int $retryAfterSeconds): self
    {
        return new self(
            "Please wait {$retryAfterSeconds} seconds before requesting another code.",
            ApiErrorCode::OtpResendCooldown,
            Response::HTTP_TOO_MANY_REQUESTS,
            ['retry_after' => $retryAfterSeconds],
        );
    }
}
