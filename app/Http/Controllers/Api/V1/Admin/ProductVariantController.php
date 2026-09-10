<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Portion sizes for one product.
 *
 * The parent product is resolved through the tenant-scoped query first, so a
 * product id from another restaurant is a 404 before any variant is touched.
 * The composite foreign key would refuse the write regardless.
 */
class ProductVariantController extends Controller
{
    public function index(int $product): JsonResponse
    {
        $parent = $this->parent($product);

        $this->authorize('view', $parent);

        return ApiResponse::success(
            data: ProductVariantResource::collection(
                $parent->variants()->orderBy('sort_order')->orderBy('id')->get()
            )->resolve(),
            message: 'Variants fetched successfully',
        );
    }

    public function store(Request $request, int $product): JsonResponse
    {
        $parent = $this->parent($product);

        $this->authorize('update', $parent);

        $validated = $this->validated($request, $parent);

        $variant = DB::transaction(function () use ($parent, $validated): ProductVariant {
            // restaurant_id is guarded and stamped by BelongsToRestaurant from
            // the tenant context, so it is never passed in from here.
            $variant = $parent->variants()->create($validated);

            $this->enforceSingleDefault($parent, $variant);

            return $variant;
        });

        return ApiResponse::created(new ProductVariantResource($variant->fresh()), 'Variant created successfully');
    }

    public function update(Request $request, int $product, int $variant): JsonResponse
    {
        $parent = $this->parent($product);

        $this->authorize('update', $parent);

        $model = $parent->variants()->findOrFail($variant);
        $validated = $this->validated($request, $parent, $model->getKey());

        DB::transaction(function () use ($model, $validated, $parent): void {
            $model->fill($validated)->save();

            $this->enforceSingleDefault($parent, $model);
        });

        return ApiResponse::success(new ProductVariantResource($model->fresh()), 'Variant updated successfully');
    }

    public function destroy(int $product, int $variant): JsonResponse
    {
        $parent = $this->parent($product);

        $this->authorize('delete', $parent);

        $model = $parent->variants()->findOrFail($variant);

        // Cart lines pointing at this variant are removed by the composite
        // foreign key cascade; order items keep their own snapshot of the name.
        $model->delete();

        return ApiResponse::noContent('Variant deleted successfully');
    }

    private function validated(Request $request, Product $parent, ?int $ignoreId = null): array
    {
        $required = $ignoreId === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [
                $required, 'string', 'max:100',
                Rule::unique('product_variants', 'name')
                    ->where('product_id', $parent->getKey())
                    ->ignore($ignoreId),
            ],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Absolute price in minor units: a variant replaces base_price
            // rather than adjusting it, which keeps pricing free of sign errors.
            'price' => [$required, 'integer', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ], [
            'name.unique' => 'This product already has a variant with that name.',
        ]);
    }

    /** At most one default per product, so the client always has one to preselect. */
    private function enforceSingleDefault(Product $parent, ProductVariant $variant): void
    {
        if (! $variant->is_default) {
            return;
        }

        $parent->variants()
            ->whereKeyNot($variant->getKey())
            ->update(['is_default' => false]);
    }

    private function parent(int $product): Product
    {
        return Product::query()->findOrFail($product);
    }
}
