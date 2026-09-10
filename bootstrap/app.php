<?php

use App\Enums\ApiErrorCode;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\ResetTenantContext::class,
        ]);

        $middleware->alias([
            'ability' => \App\Http\Middleware\EnsureTokenAbility::class,
            'user.type' => \App\Http\Middleware\EnsureUserType::class,
            'tenant.staff' => \App\Http\Middleware\ResolveStaffRestaurant::class,
            'tenant.customer' => \App\Http\Middleware\ResolveCustomerStore::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Every API failure leaves through here, so a 500 is structurally
         * identical to a 200. App\Exceptions\Api\ApiException subclasses render
         * themselves and never reach this closure.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    message: 'Validation failed',
                    code: ApiErrorCode::ValidationError,
                    status: Response::HTTP_UNPROCESSABLE_ENTITY,
                    errors: $e->errors(),
                ),

                $e instanceof AuthenticationException => ApiResponse::error(
                    message: 'Authentication required.',
                    code: ApiErrorCode::Unauthenticated,
                    status: Response::HTTP_UNAUTHORIZED,
                ),

                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => ApiResponse::error(
                    message: 'You are not allowed to perform this action.',
                    code: ApiErrorCode::Forbidden,
                    status: Response::HTTP_FORBIDDEN,
                ),

                // Model binding failures must not disclose whether the record
                // exists under another tenant — same 404 either way.
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    message: 'Resource not found.',
                    code: ApiErrorCode::NotFound,
                    status: Response::HTTP_NOT_FOUND,
                ),

                $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                    message: 'This method is not allowed for this endpoint.',
                    code: ApiErrorCode::MethodNotAllowed,
                    status: Response::HTTP_METHOD_NOT_ALLOWED,
                ),

                $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                    message: 'Too many requests. Please slow down.',
                    code: ApiErrorCode::TooManyRequests,
                    status: Response::HTTP_TOO_MANY_REQUESTS,
                ),

                default => ApiResponse::error(
                    // Never leak SQL, file paths or table names to a client.
                    message: config('app.debug')
                        ? $e->getMessage()
                        : 'Something went wrong. Please try again.',
                    code: ApiErrorCode::ServerError,
                    status: $e instanceof HttpExceptionInterface
                        ? $e->getStatusCode()
                        : Response::HTTP_INTERNAL_SERVER_ERROR,
                ),
            };
        });
    })->create();
