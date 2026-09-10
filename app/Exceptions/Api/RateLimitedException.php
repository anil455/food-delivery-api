<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

use App\Enums\ApiErrorCode;
use Illuminate\Http\Response;

/**
 * A quota was exhausted. Always carries retry_after so the client can show a
 * countdown rather than inviting the user to hammer the button.
 */
final class RateLimitedException extends ApiException
{
    public function __construct(string $message, int $retryAfterSeconds)
    {
        parent::__construct(
            message: $message,
            errorCode: ApiErrorCode::TooManyRequests,
            status: Response::HTTP_TOO_MANY_REQUESTS,
            payload: ['retry_after' => $retryAfterSeconds],
        );
    }
}
