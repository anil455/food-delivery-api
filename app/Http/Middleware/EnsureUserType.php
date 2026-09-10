<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Keeps customer routes and staff routes apart at the User.type level. */
final class EnsureUserType
{
    public function handle(Request $request, Closure $next, string ...$types): SymfonyResponse
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error(
                message: 'Authentication required.',
                code: ApiErrorCode::Unauthenticated,
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        if (! in_array($user->type->value, $types, true)) {
            return ApiResponse::error(
                message: 'This account cannot access this endpoint.',
                code: ApiErrorCode::Forbidden,
                status: Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
