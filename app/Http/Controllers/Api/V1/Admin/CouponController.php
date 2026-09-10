<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CouponType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CouponResource;
use App\Models\Coupon;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Discount codes owned by one restaurant.
 *
 * Coupon is not tenant-scoped, because a platform-wide coupon carries a null
 * restaurant_id and the global scope would hide it from everyone. Isolation
 * here is therefore explicit: every query filters on the tenant id, and a
 * restaurant can neither read nor edit a platform coupon.
 */
class CouponController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Coupon::class);

        $coupons = Coupon::query()
            ->where('restaurant_id', $this->context->requireId())
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(
            data: CouponResource::collection($coupons)->resolve(),
            message: 'Coupons fetched successfully',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Coupon::class);

        $coupon = new Coupon;

        // restaurant_id is set here rather than accepted from input, so a
        // restaurant cannot mint a platform-wide coupon by sending null.
        $coupon->forceFill([
            ...$this->validated($request),
            'restaurant_id' => $this->context->requireId(),
            'times_used' => 0,
        ])->save();

        // Columns that fall back to a database default (is_active, times_used)
        // are not on the instance until it is reloaded, and isLive() reads them.
        return ApiResponse::created(new CouponResource($coupon->refresh()), 'Coupon created successfully');
    }

    public function show(int $coupon): JsonResponse
    {
        $model = $this->ownedOrFail($coupon);

        $this->authorize('view', $model);

        return ApiResponse::success(new CouponResource($model), 'Coupon fetched successfully');
    }

    public function update(Request $request, int $coupon): JsonResponse
    {
        $model = $this->ownedOrFail($coupon);

        $this->authorize('update', $model);

        $model->fill($this->validated($request, $model->getKey()))->save();

        return ApiResponse::success(new CouponResource($model->fresh()), 'Coupon updated successfully');
    }

    public function destroy(int $coupon): JsonResponse
    {
        $model = $this->ownedOrFail($coupon);

        $this->authorize('delete', $model);

        // Soft delete: orders reference the coupon and keep its code snapshot.
        $model->delete();

        return ApiResponse::noContent('Coupon deleted successfully');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $restaurantId = $this->context->requireId();
        $required = $ignoreId === null ? 'required' : 'sometimes';

        $validated = $request->validate([
            'code' => [
                $required, 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('coupons', 'code')
                    ->where('restaurant_id', $restaurantId)
                    ->ignore($ignoreId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => [$required, Rule::in(['fixed', 'percent'])],
            /*
             * `value` means different things per type: minor units for a fixed
             * discount, a whole percent for a percentage one. The max:100 rule
             * below only applies to percent, checked after validation.
             */
            'value' => [$required, 'integer', 'min:1'],
            'max_discount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'min_order_amount' => ['sometimes', 'integer', 'min:0'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_user_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.regex' => 'Use uppercase letters, digits, hyphens and underscores only.',
            'code.unique' => 'This restaurant already has a coupon with that code.',
        ]);

        if (($validated['type'] ?? null) === CouponType::Percent->value && ($validated['value'] ?? 0) > 100) {
            abort(422, 'A percentage coupon cannot exceed 100 percent.');
        }

        return $validated;
    }

    /**
     * Scoped by hand, because Coupon opts out of the global scope. A platform
     * coupon has a null restaurant_id and so never matches.
     */
    private function ownedOrFail(int $id): Coupon
    {
        return Coupon::query()
            ->where('restaurant_id', $this->context->requireId())
            ->findOrFail($id);
    }
}
