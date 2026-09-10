<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\RestaurantStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CategoryResource;
use App\Http\Resources\V1\ProductResource;
use App\Http\Resources\V1\RestaurantHourResource;
use App\Http\Resources\V1\RestaurantResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'city' => ['sometimes', 'string', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $restaurants = $this->context->runCrossTenant(
            fn () => Restaurant::query()
                ->where('status', RestaurantStatus::Active->value)
                ->when($validated['city'] ?? null, fn ($q, $city) => $q->where('city', $city))
                ->when($validated['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', '%'.$search.'%'))
                ->with('hours')
                ->orderBy('name')
                ->paginate($validated['per_page'] ?? 15)
        );

        return ApiResponse::paginated(
            RestaurantResource::collection($restaurants),
            'Restaurants fetched successfully',
        );
    }

    public function show(string $slug): JsonResponse
    {
        $restaurant = $this->findActiveOrFail($slug);

        return ApiResponse::success(
            data: new RestaurantResource($restaurant),
            message: 'Restaurant fetched successfully',
        );
    }

    public function hours(string $slug): JsonResponse
    {
        $restaurant = $this->findActiveOrFail($slug);

        return ApiResponse::success(
            data: RestaurantHourResource::collection($restaurant->getRelation('hours'))->resolve(),
            message: 'Restaurant hours fetched successfully',
        );
    }

    /**
     * Public menu for one restaurant.
     *
     * Resolving the slug sets the tenant context, so the category and product
     * queries below are ordinary scoped Eloquent with no restaurant_id filter
     * written by hand. A customer browsing without an account gets exactly the
     * same isolation guarantees as a signed-in one.
     */
    public function categories(string $slug): JsonResponse
    {
        $this->contextFromSlug($slug);

        $categories = Category::query()
            ->active()
            ->ordered()
            ->withCount(['products' => fn ($q) => $q->orderable()])
            ->get();

        return ApiResponse::success(
            data: CategoryResource::collection($categories)->resolve(),
            message: 'Categories fetched successfully',
        );
    }

    public function products(Request $request, string $slug): JsonResponse
    {
        $this->contextFromSlug($slug);

        $validated = $request->validate([
            'category_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:100'],
            'is_veg' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->orderable()
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

    public function product(string $slug, string $productSlug): JsonResponse
    {
        $this->contextFromSlug($slug);

        $product = Product::query()
            ->with([
                'category',
                'variants' => fn ($q) => $q->where('is_available', true)->orderBy('sort_order'),
                'addonGroups' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'addonGroups.addons' => fn ($q) => $q->where('is_available', true)->orderBy('sort_order'),
            ])
            ->orderable()
            ->where('slug', $productSlug)
            ->firstOrFail();

        return ApiResponse::success(
            data: new ProductResource($product),
            message: 'Product fetched successfully',
        );
    }

    /** Resolve the slug and make that restaurant the tenant for this request. */
    private function contextFromSlug(string $slug): Restaurant
    {
        $restaurant = $this->findActiveOrFail($slug);

        $this->context->set($restaurant);

        return $restaurant;
    }

    /**
     * Public reads span tenants by definition: the caller is choosing a store,
     * so no context exists yet. An inactive restaurant is a 404 rather than a
     * 403, so its existence is not disclosed.
     */
    private function findActiveOrFail(string $slug): Restaurant
    {
        return $this->context->runCrossTenant(
            fn () => Restaurant::query()
                ->where('slug', $slug)
                ->where('status', RestaurantStatus::Active->value)
                ->with(['hours', 'holidays'])
                ->firstOrFail()
        );
    }
}
