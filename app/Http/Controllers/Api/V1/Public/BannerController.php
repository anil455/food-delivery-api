<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\BannerRequest;
use App\Http\Resources\V1\BannerResource;
use App\Services\Geo\BannerFinder;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class BannerController extends Controller
{
    public function __construct(private readonly BannerFinder $finder) {}

    public function __invoke(BannerRequest $request): JsonResponse
    {
        $banners = $this->finder->find($request->origin());

        return ApiResponse::success(
            data: BannerResource::collection($banners)->resolve(),
            message: 'Banners fetched successfully',
        );
    }
}
