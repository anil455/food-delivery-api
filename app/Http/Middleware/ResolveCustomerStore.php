<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Models\Restaurant;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Layer 2 for customer routes: resolves which store the customer is browsing.
 *
 * Precedence is explicit request over stored preference, so a customer can look
 * at another store without first committing to it. Whatever the source, the
 * restaurant must still be active before it becomes the tenant context.
 */
final class ResolveCustomerStore
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $user = $request->user();

        $requested = $request->query('restaurant_id')
            ?? $request->route('restaurant')
            ?? $user?->selected_restaurant_id;

        if ($requested instanceof Restaurant) {
            $restaurant = $requested;
        } elseif ($requested === null) {
            return ApiResponse::error(
                message: 'No store selected. Choose a restaurant before browsing the menu.',
                code: ApiErrorCode::NoStoreSelected,
                status: Response::HTTP_BAD_REQUEST,
            );
        } else {
            $restaurant = Restaurant::query()->find((int) $requested);
        }

        if ($restaurant === null) {
            return ApiResponse::error(
                message: 'Resource not found.',
                code: ApiErrorCode::NotFound,
                status: Response::HTTP_NOT_FOUND,
            );
        }

        // A store that has been deactivated since the customer last chose it
        // must not silently keep serving its menu.
        if (! $restaurant->isActive()) {
            return ApiResponse::error(
                message: 'This restaurant is not currently available.',
                code: ApiErrorCode::RestaurantNotAccessible,
                status: Response::HTTP_FORBIDDEN,
            );
        }

        $this->context->set($restaurant);

        return $next($request);
    }
}
