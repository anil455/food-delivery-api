<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RestaurantResource;
use App\Models\Restaurant;
use App\Services\Geo\GeoPoint;
use App\Services\Restaurant\StoreSelectionService;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Which store the customer is currently shopping in.
 *
 * The frontend calls GET once on launch and gets either the store the customer
 * chose or the nearest one that delivers to them, so a first-time user lands on
 * a working menu without picking anything.
 */
class StoreContextController extends Controller
{
    public function __construct(
        private readonly StoreSelectionService $stores,
        private readonly RestaurantContext $context,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['sometimes', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ]);

        $origin = isset($validated['latitude'], $validated['longitude'])
            ? new GeoPoint((float) $validated['latitude'], (float) $validated['longitude'])
            : null;

        $user = $request->user();
        $chosen = $this->stores->chosenStore($user);
        $restaurant = $chosen ?? $this->stores->nearestFor($user, $origin);

        if ($restaurant === null) {
            return ApiResponse::success(
                data: ['restaurant' => null, 'source' => 'none'],
                message: 'No store selected. Search for restaurants near you to choose one.',
            );
        }

        return $this->context->runCrossTenant(fn () => ApiResponse::success(
            data: [
                'restaurant' => (new RestaurantResource($restaurant))->resolve(),
                // The client shows a different prompt for an auto-picked store
                // than for one the customer chose deliberately.
                'source' => $chosen !== null ? 'selected' : 'nearest',
            ],
            message: 'Store context fetched successfully',
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
        ]);

        $restaurant = $this->context->runCrossTenant(
            fn () => Restaurant::query()->with(['hours', 'holidays'])->find($validated['restaurant_id'])
        );

        if ($restaurant === null || ! $restaurant->isActive()) {
            return ApiResponse::error(
                message: 'This restaurant is not currently available.',
                code: ApiErrorCode::RestaurantNotAccessible,
                status: Response::HTTP_FORBIDDEN,
            );
        }

        $this->stores->select($request->user(), $restaurant);

        return $this->context->runCrossTenant(fn () => ApiResponse::success(
            data: [
                'restaurant' => (new RestaurantResource($restaurant))->resolve(),
                'source' => 'selected',
            ],
            message: 'Store selected successfully',
        ));
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->stores->clear($request->user());

        return ApiResponse::success(message: 'Store selection cleared successfully');
    }
}
