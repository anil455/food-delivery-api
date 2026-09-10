<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CategoryResource;
use App\Http\Resources\V1\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The menu of whichever store the customer is currently in.
 *
 * Every query here is plain Eloquent with no restaurant_id in sight: the tenant
 * middleware has already set the context, and the global scope adds the filter.
 * That is the whole point of the scope — a controller cannot forget to apply it,
 * because it never applies it in the first place.
 */
class MenuController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        $withProducts = $request->boolean('with_products');

        $categories = Category::query()
            ->active()
            ->ordered()
            ->when(
                $withProducts,
                fn ($query) => $query->with(['products' => fn ($q) => $q->orderable()->ordered()]),
                fn ($query) => $query->withCount(['products' => fn ($q) => $q->orderable()]),
            )
            ->get();

        return ApiResponse::success(
            data: CategoryResource::collection($categories)->resolve(),
            message: 'Categories fetched successfully',
        );
    }

    public function products(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:100'],
            'is_veg' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->orderable()
            // A category id from another restaurant simply matches nothing,
            // because the scope has already constrained the table.
            ->when($validated['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($validated['search'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->when($request->has('is_veg'), fn ($q) => $q->where('is_veg', $request->boolean('is_veg')))
            ->ordered()
            ->paginate($validated['per_page'] ?? 20);

        return ApiResponse::paginated(
            ProductResource::collection($products),
            'Products fetched successfully',
        );
    }

    public function show(int $product): JsonResponse
    {
        $model = Product::query()
            ->with([
                'category',
                'variants' => fn ($q) => $q->where('is_available', true)->orderBy('sort_order'),
                'addonGroups' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'addonGroups.addons' => fn ($q) => $q->where('is_available', true)->orderBy('sort_order'),
            ])
            ->orderable()
            ->findOrFail($product);

        return ApiResponse::success(
            data: new ProductResource($model),
            message: 'Product fetched successfully',
        );
    }
}
