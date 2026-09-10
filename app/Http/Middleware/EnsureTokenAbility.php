<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Coarse audience gate. A customer token can never reach an admin route even if
 * a policy is missing, because the token simply does not carry the ability.
 */
final class EnsureTokenAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): SymfonyResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null) {
            return ApiResponse::error(
                message: 'Authentication required.',
                code: ApiErrorCode::Unauthenticated,
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        foreach ($abilities as $ability) {
            if ($token->can($ability)) {
                return $next($request);
            }
        }

        return ApiResponse::error(
            message: 'This token is not permitted to access this endpoint.',
            code: ApiErrorCode::Forbidden,
            status: Response::HTTP_FORBIDDEN,
        );
    }
}
