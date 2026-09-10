<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

use App\Enums\ApiErrorCode;
use Illuminate\Http\Response;

/**
 * A staff user asked to act on a restaurant they have no membership for.
 * Returned as 403 with no detail about whether the restaurant exists.
 */
final class RestaurantNotAccessibleException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            message: 'You do not have access to this restaurant.',
            errorCode: ApiErrorCode::RestaurantNotAccessible,
            status: Response::HTTP_FORBIDDEN,
        );
    }
}
