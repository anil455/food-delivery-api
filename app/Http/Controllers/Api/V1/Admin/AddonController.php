<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AddonResource;
use App\Models\Addon;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Individual options inside an add-on group.
 *
 * Everything here is tenant-scoped, so an id belonging to another restaurant is
 * a 404 rather than a leak, and the composite foreign key refuses a group from
 * a different restaurant even if this validation were removed.
 */
class AddonController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Addon::class);

        $validated = $request->validate([
            'addon_group_id' => ['sometimes', 'integer'],
        ]);

        $addons = Addon::query()
            ->when($validated['addon_group_id'] ?? null, fn ($q, $id) => $q->where('addon_group_id', $id))
            ->with('group')
            ->orderBy('addon_group_id')
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success(
            data: AddonResource::collection($addons)->resolve(),
            message: 'Add-ons fetched successfully',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Addon::class);

        $addon = Addon::query()->create($this->validated($request));

        return ApiResponse::created(new AddonResource($addon), 'Add-on created successfully');
    }

    public function update(Request $request, int $addon): JsonResponse
    {
        $model = Addon::query()->findOrFail($addon);

        $this->authorize('update', $model);

        $model->fill($this->validated($request, false))->save();

        return ApiResponse::success(new AddonResource($model->fresh()), 'Add-on updated successfully');
    }

    /** The sold-out toggle for a single option, available to shift staff. */
    public function availability(Request $request, int $addon): JsonResponse
    {
        $model = Addon::query()->findOrFail($addon);

        $this->authorize('update', $model);

        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $model->forceFill(['is_available' => $validated['is_available']])->save();

        return ApiResponse::success(
            data: ['id' => $model->id, 'is_available' => $model->is_available],
            message: $validated['is_available'] ? 'Add-on marked available' : 'Add-on marked unavailable',
        );
    }

    public function destroy(int $addon): JsonResponse
    {
        $model = Addon::query()->findOrFail($addon);

        $this->authorize('delete', $model);

        // Soft delete: order item add-ons keep their own name and price snapshot.
        $model->delete();

        return ApiResponse::noContent('Add-on deleted successfully');
    }

    private function validated(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'addon_group_id' => [
                $required, 'integer',
                Rule::exists('addon_groups', 'id')
                    ->where('restaurant_id', $this->context->requireId())
                    ->whereNull('deleted_at'),
            ],
            'name' => [$required, 'string', 'max:255'],
            // Minor units, so no float ever reaches a price column.
            'price' => ['sometimes', 'integer', 'min:0'],
            'is_available' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ], [
            'addon_group_id.exists' => 'That add-on group does not belong to this restaurant.',
        ]);
    }
}
