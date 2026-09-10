<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductResource;
use App\Models\Product;
use App\Services\Media\ImageStorageService;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $validated = $request->validate([
            'category_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:100'],
            'is_available' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->when($validated['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($validated['search'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->when($request->has('is_available'), fn ($q) => $q->where('is_available', $request->boolean('is_available')))
            ->with('category')
            ->ordered()
            ->paginate($validated['per_page'] ?? 20);

        return ApiResponse::paginated(ProductResource::collection($products), 'Products fetched successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = Product::query()->create($this->validated($request));

        return ApiResponse::created(new ProductResource($product->load('category')), 'Product created successfully');
    }

    public function show(int $product): JsonResponse
    {
        $model = Product::query()
            ->with(['category', 'variants', 'addonGroups.addons'])
            ->findOrFail($product);

        $this->authorize('view', $model);

        return ApiResponse::success(new ProductResource($model), 'Product fetched successfully');
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $model = Product::query()->findOrFail($product);

        $this->authorize('update', $model);

        $model->fill($this->validated($request, $model->getKey()))->save();

        return ApiResponse::success(
            new ProductResource($model->fresh('category')),
            'Product updated successfully',
        );
    }

    public function destroy(int $product): JsonResponse
    {
        $model = Product::query()->findOrFail($product);

        $this->authorize('delete', $model);

        // Soft delete only: order items link back to this row for reorder.
        $model->delete();

        return ApiResponse::noContent('Product deleted successfully');
    }

    /**
     * The sold-out toggle. Separate from update because it is the one product
     * change a shift worker makes constantly, and it needs only staff rights.
     */
    public function availability(Request $request, int $product): JsonResponse
    {
        $model = Product::query()->findOrFail($product);

        $this->authorize('update', $model);

        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $model->forceFill(['is_available' => $validated['is_available']])->save();

        return ApiResponse::success(
            data: ['id' => $model->id, 'is_available' => $model->is_available],
            message: $validated['is_available'] ? 'Product marked available' : 'Product marked sold out',
        );
    }

    /**
     * Replace a product image.
     *
     * Two layers: the `image` rule decodes the upload so a renamed script
     * cannot pass as a JPEG, and ImageStorageService then re-encodes it, so
     * only freshly written pixels reach disk. Nothing of the uploaded bytes
     * survives.
     */
    public function uploadImage(Request $request, int $product, ImageStorageService $images): JsonResponse
    {
        $model = Product::query()->findOrFail($product);

        $this->authorize('update', $model);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=200,min_height=200'],
        ]);

        $previous = $model->image_path;

        $path = $images->store(
            $request->file('image'),
            'restaurants/'.$model->restaurant_id.'/products',
        );

        $model->forceFill(['image_path' => $path])->save();

        // Remove the old file only after the new one is committed, so a failed
        // write never leaves the product with no image at all.
        if ($previous !== $path) {
            $images->delete($previous);
        }

        return ApiResponse::success(
            data: [
                'id' => $model->id,
                'image_path' => $path,
                'image_url' => $images->url($path),
            ],
            message: 'Product image updated successfully',
        );
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $restaurantId = $this->context->requireId();
        $required = $ignoreId === null ? 'required' : 'sometimes';

        $validated = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            // The category must belong to this restaurant. The composite foreign
            // key would reject a cross-tenant pairing anyway; this turns a
            // constraint violation into a readable validation error.
            'category_id' => [
                $required, 'integer',
                Rule::exists('categories', 'id')
                    ->where('restaurant_id', $restaurantId)
                    ->whereNull('deleted_at'),
            ],
            'slug' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('products', 'slug')
                    ->where('restaurant_id', $restaurantId)
                    ->ignore($ignoreId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Minor units, so no float ever reaches a price column.
            'base_price' => [$required, 'integer', 'min:0'],
            'compare_at_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'tax_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'is_veg' => ['sometimes', 'boolean'],
            'spice_level' => ['sometimes', 'nullable', 'integer', 'between:0,5'],
            'prep_time_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:240'],
            'is_available' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ], [
            'category_id.exists' => 'That category does not belong to this restaurant.',
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

        while (Product::withoutTenantScope()
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
