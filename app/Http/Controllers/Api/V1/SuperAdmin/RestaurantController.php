<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Enums\RestaurantStatus;
use App\Enums\StaffRole;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RestaurantResource;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Platform administration of restaurants.
 *
 * Only this controller creates restaurants, changes their status or sets their
 * commission rate. Restaurant staff cannot do any of those, which is why
 * RestaurantPolicy::create and ::delete return false unconditionally and only
 * the Gate::before super-admin short-circuit lets these through.
 */
class RestaurantController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(array_map(fn (RestaurantStatus $s): string => $s->value, RestaurantStatus::cases()))],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // Cross-tenant by design: this is the platform view.
        $restaurants = $this->context->runCrossTenant(
            fn () => Restaurant::query()
                ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($validated['search'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
                ->withCount(['orders', 'products'])
                ->with('hours')
                ->orderBy('name')
                ->paginate($validated['per_page'] ?? 20)
        );

        return ApiResponse::paginated(
            RestaurantResource::collection($restaurants),
            'Restaurants fetched successfully',
        );
    }

    public function show(int $restaurant): JsonResponse
    {
        return ApiResponse::success(
            data: new RestaurantResource($this->find($restaurant)),
            message: 'Restaurant fetched successfully',
        );
    }

    /**
     * Create a restaurant and its first owner in one transaction. A restaurant
     * with no owner is unmanageable, so the two are never created separately.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Restaurant::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('restaurants', 'slug')],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address_line' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'max:20'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'delivery_radius_km' => ['sometimes', 'numeric', 'min:0.1', 'max:50'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'status' => ['sometimes', Rule::in(array_map(fn (RestaurantStatus $s): string => $s->value, RestaurantStatus::cases()))],
            'commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],

            'owner.name' => ['required', 'string', 'max:255'],
            'owner.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'owner.password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $restaurant = DB::transaction(function () use ($validated): Restaurant {
            $restaurant = new Restaurant;

            $restaurant->forceFill([
                ...collect($validated)->except('owner')->all(),
                'slug' => $validated['slug'] ?? $this->uniqueSlug($validated['name']),
                'timezone' => $validated['timezone'] ?? 'Asia/Kolkata',
                'status' => $validated['status'] ?? RestaurantStatus::PendingApproval->value,
                'delivery_radius_km' => $validated['delivery_radius_km'] ?? config('delivery.default_delivery_radius_km'),
                'avg_prep_time_minutes' => config('delivery.default_prep_time_minutes'),
                'tax_percentage' => config('delivery.default_tax_percentage'),
            ])->save();

            $owner = new User;
            $owner->forceFill([
                'name' => $validated['owner']['name'],
                'email' => $validated['owner']['email'],
                'password' => $validated['owner']['password'],
                'email_verified_at' => now(),
                'type' => UserType::Staff,
                'status' => 'active',
                'is_super_admin' => false,
            ])->save();

            $restaurant->staff()->attach($owner->getKey(), [
                'role' => StaffRole::Owner->value,
                'status' => 'active',
            ]);

            return $restaurant;
        });

        return ApiResponse::created(
            new RestaurantResource($restaurant->load('hours')),
            'Restaurant created successfully',
        );
    }

    public function update(Request $request, int $restaurant): JsonResponse
    {
        $model = $this->find($restaurant);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'delivery_radius_km' => ['sometimes', 'numeric', 'min:0.1', 'max:50'],
            'timezone' => ['sometimes', 'string', 'timezone'],
        ]);

        $model->forceFill($validated)->save();

        return ApiResponse::success(new RestaurantResource($model->fresh('hours')), 'Restaurant updated successfully');
    }

    /**
     * Enable, disable or suspend a restaurant. This is the platform kill switch:
     * anything but active drops the restaurant out of customer discovery
     * entirely, and suspended also blocks staff writes.
     */
    public function updateStatus(Request $request, int $restaurant): JsonResponse
    {
        $model = $this->find($restaurant);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_map(fn (RestaurantStatus $s): string => $s->value, RestaurantStatus::cases()))],
        ]);

        $model->forceFill(['status' => $validated['status']])->save();

        return ApiResponse::success(
            data: ['id' => $model->id, 'status' => $model->status->value],
            message: 'Restaurant status updated successfully',
        );
    }

    public function destroy(int $restaurant): JsonResponse
    {
        $model = $this->find($restaurant);

        // Soft delete: orders reference this restaurant with restrictOnDelete
        // and must remain readable for accounting.
        $model->delete();

        return ApiResponse::noContent('Restaurant deleted successfully');
    }

    private function find(int $id): Restaurant
    {
        return $this->context->runCrossTenant(
            fn () => Restaurant::query()->with('hours')->findOrFail($id)
        );
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Restaurant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
