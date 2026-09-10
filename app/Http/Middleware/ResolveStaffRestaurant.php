<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Exceptions\Api\RestaurantNotAccessibleException;
use App\Models\Restaurant;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Layer 2 for staff routes: turns a request header into a tenant context, but
 * only after proving the caller is entitled to it.
 *
 * The header is a hint, never an authorisation. An id the user has no active
 * membership for is rejected with 403 and no information about whether the
 * restaurant exists.
 */
final class ResolveStaffRestaurant
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error(
                message: 'Authentication required.',
                code: ApiErrorCode::Unauthenticated,
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        $header = (string) config('tenancy.restaurant_header');
        $requested = $request->header($header);

        if ($requested === null) {
            $resolved = $this->soleMembership($user);

            if ($resolved === null) {
                return ApiResponse::error(
                    message: "Specify which restaurant you are acting on using the {$header} header.",
                    code: ApiErrorCode::TenantContextMissing,
                    status: Response::HTTP_BAD_REQUEST,
                );
            }

            $this->context->set($resolved);

            return $next($request);
        }

        if (! ctype_digit((string) $requested)) {
            throw new RestaurantNotAccessibleException;
        }

        $restaurantId = (int) $requested;

        // Super admins may act on any restaurant; everyone else needs a row in
        // restaurant_users. Both paths still require the restaurant to exist.
        if (! $user->hasRoleInRestaurant($restaurantId)) {
            throw new RestaurantNotAccessibleException;
        }

        $restaurant = Restaurant::query()->find($restaurantId);

        if ($restaurant === null) {
            throw new RestaurantNotAccessibleException;
        }

        $this->context->set($restaurant);

        return $next($request);
    }

    /**
     * Staff who belong to exactly one restaurant should not have to send a
     * header at all. Ambiguity, not convenience, is what requires the header.
     */
    private function soleMembership($user): ?Restaurant
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        $memberships = $user->memberships()->where('status', 'active')->get();

        if ($memberships->count() !== 1) {
            return null;
        }

        return Restaurant::query()->find($memberships->first()->restaurant_id);
    }
}
