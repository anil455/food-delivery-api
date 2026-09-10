<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RestaurantHourResource;
use App\Http\Resources\V1\RestaurantResource;
use App\Models\Restaurant;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The restaurant the authenticated staff member is acting on.
 *
 * There is no id in any of these routes: the tenant middleware resolved the
 * restaurant from a validated membership, so a staff member cannot even express
 * the idea of editing someone else.
 */
class RestaurantController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function show(): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('view', $restaurant);

        return ApiResponse::success(
            data: new RestaurantResource($restaurant->load('hours')),
            message: 'Restaurant fetched successfully',
        );
    }

    public function update(Request $request): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('update', $restaurant);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address_line' => ['sometimes', 'string', 'max:255'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:100'],
            'state' => ['sometimes', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'string', 'max:20'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'delivery_radius_km' => ['sometimes', 'numeric', 'min:0.1', 'max:50'],
            'min_order_amount' => ['sometimes', 'integer', 'min:0'],
            'delivery_fee_base' => ['sometimes', 'integer', 'min:0'],
            'delivery_fee_per_km' => ['sometimes', 'integer', 'min:0'],
            'packaging_fee' => ['sometimes', 'integer', 'min:0'],
            'tax_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'avg_prep_time_minutes' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'timezone' => ['sometimes', 'string', 'timezone'],
        ]);

        // status and commission_rate are guarded on the model: only the platform
        // changes those, through the super-admin routes.
        $restaurant->fill($validated)->save();

        return ApiResponse::success(
            data: new RestaurantResource($restaurant->fresh('hours')),
            message: 'Restaurant updated successfully',
        );
    }

    /** The pause switch a kitchen uses when it is swamped. */
    public function toggleOrders(Request $request): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('toggleOrders', $restaurant);

        $validated = $request->validate([
            'is_accepting_orders' => ['required', 'boolean'],
        ]);

        $restaurant->forceFill(['is_accepting_orders' => $validated['is_accepting_orders']])->save();

        return ApiResponse::success(
            data: ['is_accepting_orders' => $restaurant->is_accepting_orders],
            message: $validated['is_accepting_orders']
                ? 'Now accepting orders'
                : 'Orders paused',
        );
    }

    public function hours(): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('view', $restaurant);

        return ApiResponse::success(
            data: RestaurantHourResource::collection($restaurant->hours()->orderBy('day_of_week')->orderBy('opens_at')->get())->resolve(),
            message: 'Restaurant hours fetched successfully',
        );
    }

    /**
     * The whole week is replaced in one call rather than patched slot by slot.
     * A weekly schedule is edited as a unit, and a partial update leaves the
     * restaurant in a state nobody intended.
     */
    public function updateHours(Request $request): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('update', $restaurant);

        $validated = $request->validate([
            'hours' => ['required', 'array', 'max:50'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'hours.*.is_closed' => ['sometimes', 'boolean'],
            'hours.*.opens_at' => ['required_if:hours.*.is_closed,false', 'nullable', 'date_format:H:i,H:i:s'],
            'hours.*.closes_at' => ['required_if:hours.*.is_closed,false', 'nullable', 'date_format:H:i,H:i:s'],
        ]);

        DB::transaction(function () use ($restaurant, $validated): void {
            $restaurant->hours()->delete();

            foreach ($validated['hours'] as $slot) {
                $restaurant->hours()->create([
                    'day_of_week' => $slot['day_of_week'],
                    'is_closed' => (bool) ($slot['is_closed'] ?? false),
                    'opens_at' => $this->normaliseTime($slot['opens_at'] ?? null),
                    'closes_at' => $this->normaliseTime($slot['closes_at'] ?? null),
                ]);
            }
        });

        return ApiResponse::success(
            data: RestaurantHourResource::collection(
                $restaurant->hours()->orderBy('day_of_week')->orderBy('opens_at')->get()
            )->resolve(),
            message: 'Restaurant hours updated successfully',
        );
    }

    private function normaliseTime(?string $time): ?string
    {
        if ($time === null) {
            return null;
        }

        return strlen($time) === 5 ? $time.':00' : $time;
    }

    private function current(): Restaurant
    {
        return $this->context->restaurant();
    }
}
