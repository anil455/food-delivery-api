<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RestaurantHolidayResource;
use App\Models\Restaurant;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One-off closures that override the weekly schedule.
 *
 * Holidays are reached through the restaurant relation rather than a scoped
 * query, because RestaurantHoliday deliberately opts out of the tenant scope as
 * public reference data. The relation is what confines them here.
 */
class HolidayController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('view', $restaurant);

        $holidays = $restaurant->holidays()
            ->when(
                ! $request->boolean('include_past'),
                fn ($query) => $query->whereDate('date', '>=', today())
            )
            ->orderBy('date')
            ->get();

        return ApiResponse::success(
            data: RestaurantHolidayResource::collection($holidays)->resolve(),
            message: 'Holidays fetched successfully',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('update', $restaurant);

        $validated = $request->validate([
            'date' => [
                'required', 'date', 'after_or_equal:today',
                // Unique per restaurant, matching the database constraint, so a
                // duplicate is a readable error rather than a 500.
                Rule::unique('restaurant_holidays', 'date')
                    ->where('restaurant_id', $restaurant->getKey()),
            ],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [
            'date.unique' => 'This restaurant already has a closure on that date.',
        ]);

        $holiday = $restaurant->holidays()->create($validated);

        return ApiResponse::created(
            new RestaurantHolidayResource($holiday),
            'Holiday added successfully',
        );
    }

    public function update(Request $request, int $holiday): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('update', $restaurant);

        $model = $restaurant->holidays()->findOrFail($holiday);

        $validated = $request->validate([
            'date' => [
                'sometimes', 'date',
                Rule::unique('restaurant_holidays', 'date')
                    ->where('restaurant_id', $restaurant->getKey())
                    ->ignore($model->getKey()),
            ],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $model->fill($validated)->save();

        return ApiResponse::success(
            new RestaurantHolidayResource($model->fresh()),
            'Holiday updated successfully',
        );
    }

    public function destroy(int $holiday): JsonResponse
    {
        $restaurant = $this->current();

        $this->authorize('update', $restaurant);

        $restaurant->holidays()->findOrFail($holiday)->delete();

        return ApiResponse::noContent('Holiday removed successfully');
    }

    private function current(): Restaurant
    {
        return $this->context->restaurant();
    }
}
