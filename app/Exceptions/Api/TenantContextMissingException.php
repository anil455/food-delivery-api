<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

use App\Enums\ApiErrorCode;
use Illuminate\Http\Response;

/**
 * Thrown by RestaurantScope when a tenant-owned model is queried with no
 * restaurant context set.
 *
 * This is deliberately loud. It means a route is missing its tenant-resolution
 * middleware, or a service is running outside a request without declaring
 * withoutTenantScope() — either way it is a bug, not a user error, and it must
 * never degrade into an unscoped query that returns another tenant's rows.
 */
final class TenantContextMissingException extends ApiException
{
    public function __construct(string $model)
    {
        parent::__construct(
            message: sprintf(
                'No restaurant context is set while querying [%s]. Apply the tenant middleware '
                .'to this route, or call %s::withoutTenantScope() if a cross-tenant read is intended.',
                $model,
                class_basename($model),
            ),
            errorCode: ApiErrorCode::TenantContextMissing,
            status: Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }
}
