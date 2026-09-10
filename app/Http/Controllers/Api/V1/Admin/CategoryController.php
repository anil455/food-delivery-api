<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CategoryResource;
use App\Models\Category;
use App\Services\Media\ImageStorageService;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Menu categories for the restaurant in context.
 *
 * Every query is plain scoped Eloquent. findOrFail on an id from another
 * restaurant returns 404 because the global scope already narrowed the table,
 * and the policy then confirms the role.
 */
class CategoryController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $categories = Category::query()
            ->ordered()
            ->withCount('products')
            ->get();

        return ApiResponse::success(
            data: CategoryResource::collection($categories)->resolve(),
            message: 'Categories fetched successfully',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $validated = $this->validated($request);

        // restaurant_id is stamped by the BelongsToRestaurant trait from the
        // tenant context, never taken from the request.
        $category = Category::query()->create($validated);

        return ApiResponse::created(new CategoryResource($category), 'Category created successfully');
    }

    public function show(int $category): JsonResponse
    {
        $model = Category::query()->withCount('products')->findOrFail($category);

        $this->authorize('view', $model);

        return ApiResponse::success(new CategoryResource($model), 'Category fetched successfully');
    }

    public function update(Request $request, int $category): JsonResponse
    {
        $model = Category::query()->findOrFail($category);

        $this->authorize('update', $model);

        $model->fill($this->validated($request, $model->getKey()))->save();

        return ApiResponse::success(new CategoryResource($model->fresh()), 'Category updated successfully');
    }

    public function destroy(int $category): JsonResponse
    {
        $model = Category::query()->findOrFail($category);

        $this->authorize('delete', $model);

        // Soft delete: existing orders reference this category through their
        // product snapshots and must stay readable.
        $model->delete();

        return ApiResponse::noContent('Category deleted successfully');
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $validated = $request->validate([
            'order' => ['required', 'array', 'max:200'],
            'order.*.id' => ['required', 'integer'],
            'order.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated['order'] as $entry) {
                // Scoped update: an id from another restaurant matches nothing.
                Category::query()
                    ->whereKey($entry['id'])
                    ->update(['sort_order' => $entry['sort_order']]);
            }
        });

        return ApiResponse::success(message: 'Categories reordered successfully');
    }

    /** Category images go through the same re-encoding path as product images. */
    public function uploadImage(Request $request, int $category, ImageStorageService $images): JsonResponse
    {
        $model = Category::query()->findOrFail($category);

        $this->authorize('update', $model);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=200,min_height=200'],
        ]);

        $previous = $model->image_path;

        $path = $images->store(
            $request->file('image'),
            'restaurants/'.$model->restaurant_id.'/categories',
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
            message: 'Category image updated successfully',
        );
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $restaurantId = $this->context->requireId();

        $validated = $request->validate([
            'name' => [$ignoreId === null ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('categories', 'slug')
                    ->where('restaurant_id', $restaurantId)
                    ->ignore($ignoreId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (! isset($validated['slug']) && isset($validated['name'])) {
            $validated['slug'] = $this->uniqueSlug($validated['name'], $restaurantId, $ignoreId);
        }

        return $validated;
    }

    private function uniqueSlug(string $name, int $restaurantId, ?int $ignoreId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Category::withoutTenantScope()
            ->where('restaurant_id', $restaurantId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
