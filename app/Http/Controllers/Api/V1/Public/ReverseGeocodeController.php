<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ReverseGeocodeRequest;
use App\Services\Geo\ReverseGeocoder;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The "current location" name shown at the top of the app, e.g. "Durga
 * Colony, Sector 39, Gurugram" — resolved from the device's coordinates.
 */
class ReverseGeocodeController extends Controller
{
    public function __construct(private readonly ReverseGeocoder $geocoder) {}

    public function __invoke(ReverseGeocodeRequest $request): JsonResponse
    {
        $result = $this->geocoder->locate($request->origin());

        if ($result === null) {
            return ApiResponse::error(
                message: 'Could not resolve an address for this location.',
                code: ApiErrorCode::GeocodeFailed,
                status: Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return ApiResponse::success(
            data: $result->toArray(),
            message: 'Location resolved successfully',
        );
    }
}
