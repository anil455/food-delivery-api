<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\NearbyRestaurantRequest;
use App\Http\Resources\V1\NearbyRestaurantResource;
use App\Services\Geo\NearbyRestaurantFinder;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class NearbyRestaurantController extends Controller
{
    public function __construct(private readonly NearbyRestaurantFinder $finder) {}

    public function __invoke(NearbyRestaurantRequest $request): JsonResponse
    {
        $restaurants = $this->finder->find(
            origin: $request->origin(),
            radiusKm: $request->radiusKm(),
            onlyDeliverable: $request->onlyDeliverable(),
            limit: $request->validated('limit'),
            search: $request->validated('search'),
        );

        return ApiResponse::success(
            data: NearbyRestaurantResource::collection($restaurants)->resolve(),
            message: 'Nearby restaurants fetched successfully',
            meta: [
                'origin' => $request->origin()->toArray(),
                'radius_km' => $request->radiusKm() ?? (float) config('geo.default_search_radius_km'),
                'count' => $restaurants->count(),
            ],
        );
    }
}
