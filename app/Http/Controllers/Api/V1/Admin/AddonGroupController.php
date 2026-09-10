<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AddonGroupResource;
use App\Models\AddonGroup;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reusable choice groups, such as "Spice level" or "Extra toppings".
 *
 * Groups attach to many products, because real kitchens reuse the same choices
 * across a menu. Attaching is a separate call from creating, so a group can be
 * defined once and hung on twenty dishes.
 */
class AddonGroupController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', AddonGroup::class);

        $groups = AddonGroup::query()
            ->with(['addons' => fn ($q) => $q->orderBy('sort_order')])
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(
            data: AddonGroupResource::collection($groups)->resolve(),
            message: 'Add-on groups fetched successfully',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AddonGroup::class);

        $group = AddonGroup::query()->create($this->validated($request));

        return ApiResponse::created(
            new AddonGroupResource($group->load('addons')),
            'Add-on group created successfully',
        );
    }

    public function show(int $addonGroup): JsonResponse
    {
        $group = AddonGroup::query()
            ->with(['addons' => fn ($q) => $q->orderBy('sort_order')])
            ->findOrFail($addonGroup);

        $this->authorize('view', $group);

        return ApiResponse::success(new AddonGroupResource($group), 'Add-on group fetched successfully');
    }

    public function update(Request $request, int $addonGroup): JsonResponse
    {
        $group = AddonGroup::query()->findOrFail($addonGroup);

        $this->authorize('update', $group);

        $group->fill($this->validated($request, $group->getKey()))->save();

        return ApiResponse::success(
            new AddonGroupResource($group->fresh('addons')),
            'Add-on group updated successfully',
        );
    }

    public function destroy(int $addonGroup): JsonResponse
    {
        $group = AddonGroup::query()->findOrFail($addonGroup);

        $this->authorize('delete', $group);

        $group->delete();

        return ApiResponse::noContent('Add-on group deleted successfully');
    }

    /**
     * Replace the set of products this group applies to.
     *
     * The exists rule pins every id to the current restaurant, so a group can
     * never be attached across tenants. The composite foreign key on the pivot
     * would refuse it anyway; this turns that into a validation error.
     */
    public function syncProducts(Request $request, int $addonGroup): JsonResponse
    {
        $group = AddonGroup::query()->findOrFail($addonGroup);

        $this->authorize('update', $group);

        $restaurantId = $this->context->requireId();

        $validated = $request->validate([
            'product_ids' => ['present', 'array', 'max:500'],
            'product_ids.*' => [
                'integer',
                Rule::exists('products', 'id')
                    ->where('restaurant_id', $restaurantId)
                    ->whereNull('deleted_at'),
            ],
        ], [
            'product_ids.*.exists' => 'One of those products does not belong to this restaurant.',
        ]);

        $pivot = [];
        foreach ($validated['product_ids'] as $index => $productId) {
            $pivot[$productId] = ['restaurant_id' => $restaurantId, 'sort_order' => $index];
        }

        $group->products()->sync($pivot);

        return ApiResponse::success(
            data: ['addon_group_id' => $group->getKey(), 'product_count' => count($pivot)],
            message: 'Add-on group applied successfully',
        );
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $required = $ignoreId === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            // The cart enforces these again on every add, because the client is
            // not trusted to police its own selection rules.
            'min_select' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'max_select' => ['sometimes', 'integer', 'min:1', 'max:20', 'gte:min_select'],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'max_select.gte' => 'The maximum selection cannot be lower than the minimum.',
        ]);
    }
}
