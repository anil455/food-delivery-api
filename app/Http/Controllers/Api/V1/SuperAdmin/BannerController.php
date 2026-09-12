<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BannerResource;
use App\Models\Banner;
use App\Services\Media\ImageStorageService;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform-wide banners. Gated purely by the `ability:admin` route
 * middleware, the same trust level as PlatformController — no per-record
 * policy check makes sense here since restaurant_id is always null.
 */
class BannerController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function index(): JsonResponse
    {
        $banners = $this->context->runCrossTenant(
            fn () => Banner::query()->platform()->ordered()->get()
        );

        return ApiResponse::success(
            data: BannerResource::collection($banners)->resolve(),
            message: 'Banners fetched successfully',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $validated['restaurant_id'] = null;

        $banner = $this->context->runCrossTenant(fn () => Banner::query()->create($validated));

        return ApiResponse::created(new BannerResource($banner), 'Banner created successfully');
    }

    public function show(int $banner): JsonResponse
    {
        return ApiResponse::success(new BannerResource($this->find($banner)), 'Banner fetched successfully');
    }

    public function update(Request $request, int $banner): JsonResponse
    {
        $model = $this->find($banner);

        $model->fill($this->validated($request))->save();

        return ApiResponse::success(new BannerResource($model->fresh()), 'Banner updated successfully');
    }

    public function destroy(int $banner): JsonResponse
    {
        $this->find($banner)->delete();

        return ApiResponse::noContent('Banner deleted successfully');
    }

    public function uploadImage(Request $request, int $banner, ImageStorageService $images): JsonResponse
    {
        $model = $this->find($banner);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=600,min_height=200'],
        ]);

        $previous = $model->image_path;

        $path = $images->store($request->file('image'), 'platform/banners');

        $model->forceFill(['image_path' => $path])->save();

        if ($previous !== $path) {
            $images->delete($previous);
        }

        return ApiResponse::success(
            data: [
                'id' => $model->id,
                'image_path' => $path,
                'image_url' => $images->url($path),
            ],
            message: 'Banner image updated successfully',
        );
    }

    private function find(int $id): Banner
    {
        return $this->context->runCrossTenant(
            fn () => Banner::query()->platform()->findOrFail($id)
        );
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'link_type' => ['sometimes', 'nullable', Rule::in(['restaurant', 'product', 'url'])],
            'link_value' => ['sometimes', 'nullable', 'string', 'max:255'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
