<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

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
 * A restaurant's own promotional banners.
 *
 * Banner has no BelongsToRestaurant global scope (restaurant_id is nullable
 * on the table for platform banners), so every query here filters by the
 * tenant context explicitly instead of relying on a scope to do it.
 */
class BannerController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Banner::class);

        $banners = Banner::query()
            ->where('restaurant_id', $this->context->requireId())
            ->ordered()
            ->get();

        return ApiResponse::success(
            data: BannerResource::collection($banners)->resolve(),
            message: 'Banners fetched successfully',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Banner::class);

        $validated = $this->validated($request);
        $validated['restaurant_id'] = $this->context->requireId();

        $banner = Banner::query()->create($validated);

        return ApiResponse::created(new BannerResource($banner), 'Banner created successfully');
    }

    public function show(int $banner): JsonResponse
    {
        $model = $this->find($banner);

        $this->authorize('view', $model);

        return ApiResponse::success(new BannerResource($model), 'Banner fetched successfully');
    }

    public function update(Request $request, int $banner): JsonResponse
    {
        $model = $this->find($banner);

        $this->authorize('update', $model);

        $model->fill($this->validated($request))->save();

        return ApiResponse::success(new BannerResource($model->fresh()), 'Banner updated successfully');
    }

    public function destroy(int $banner): JsonResponse
    {
        $model = $this->find($banner);

        $this->authorize('delete', $model);

        $model->delete();

        return ApiResponse::noContent('Banner deleted successfully');
    }

    /** Banner images go through the same re-encoding path as category/product images. */
    public function uploadImage(Request $request, int $banner, ImageStorageService $images): JsonResponse
    {
        $model = $this->find($banner);

        $this->authorize('update', $model);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=600,min_height=200'],
        ]);

        $previous = $model->image_path;

        $path = $images->store(
            $request->file('image'),
            'restaurants/'.$model->restaurant_id.'/banners',
        );

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
        return Banner::query()
            ->where('restaurant_id', $this->context->requireId())
            ->findOrFail($id);
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
