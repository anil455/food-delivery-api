<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Enums\OrderStatus;
use App\Enums\RestaurantStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderResource;
use App\Http\Resources\V1\UserResource;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform-wide reporting.
 *
 * Every query here is explicitly cross-tenant, which is the whole point of the
 * super-admin surface. The `admin` token ability is the gate, and it is only
 * ever issued to an account with is_super_admin set.
 */
class PlatformController extends Controller
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['customer', 'staff'])],
            'status' => ['sometimes', 'string', 'max:20'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->when($validated['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['search'] ?? null, fn ($q, $term) => $q->where(
                fn ($inner) => $inner
                    ->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%')
                    ->orWhere('phone', 'like', '%'.$term.'%')
            ))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25);

        return ApiResponse::paginated(UserResource::collection($users), 'Users fetched successfully');
    }

    /** Suspend or restore an account platform-wide. */
    public function updateUserStatus(Request $request, int $user): JsonResponse
    {
        $model = User::query()->findOrFail($user);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'blocked'])],
        ]);

        // A blocked account keeps its data but loses every live session at once.
        if ($validated['status'] === 'blocked') {
            $model->tokens()->delete();
        }

        $model->forceFill(['status' => $validated['status']])->save();

        return ApiResponse::success(
            data: ['id' => $model->id, 'status' => $model->status],
            message: 'User status updated successfully',
        );
    }

    public function orders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'restaurant_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(OrderStatus::values())],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'search' => ['sometimes', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = $this->context->runCrossTenant(
            fn () => Order::query()
                ->when($validated['restaurant_id'] ?? null, fn ($q, $id) => $q->where('restaurant_id', $id))
                ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($validated['date_from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
                ->when($validated['date_to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
                ->when($validated['search'] ?? null, fn ($q, $term) => $q->where('order_number', 'like', '%'.$term.'%'))
                ->with(['restaurant', 'items'])
                ->latest('id')
                ->paginate($validated['per_page'] ?? 25)
        );

        return ApiResponse::paginated(OrderResource::collection($orders), 'Orders fetched successfully');
    }

    public function stats(): JsonResponse
    {
        return $this->context->runCrossTenant(function (): JsonResponse {
            $delivered = OrderStatus::Delivered->value;
            $today = Order::query()->whereDate('created_at', today());

            return ApiResponse::success(
                data: [
                    'restaurants' => [
                        'total' => Restaurant::query()->count(),
                        'active' => Restaurant::query()->where('status', RestaurantStatus::Active->value)->count(),
                        'pending_approval' => Restaurant::query()->where('status', RestaurantStatus::PendingApproval->value)->count(),
                        'suspended' => Restaurant::query()->where('status', RestaurantStatus::Suspended->value)->count(),
                    ],
                    'users' => [
                        'customers' => User::query()->where('type', UserType::Customer->value)->count(),
                        'staff' => User::query()->where('type', UserType::Staff->value)->count(),
                    ],
                    'orders' => [
                        'total' => Order::query()->count(),
                        'today' => (clone $today)->count(),
                        'active' => Order::query()->active()->count(),
                        'delivered_today' => (clone $today)->where('status', $delivered)->count(),
                    ],
                    'revenue' => [
                        // Delivered orders only: pending and cancelled ones are
                        // not money the platform has actually earned.
                        'today' => Money::fromMinor((int) (clone $today)->where('status', $delivered)->sum('grand_total')),
                        'all_time' => Money::fromMinor((int) Order::query()->where('status', $delivered)->sum('grand_total')),
                    ],
                    'top_restaurants' => Restaurant::query()
                        ->withCount(['orders' => fn ($q) => $q->where('status', $delivered)])
                        ->orderByDesc('orders_count')
                        ->limit(5)
                        ->get(['id', 'name', 'slug'])
                        ->map(fn (Restaurant $r): array => [
                            'id' => $r->id,
                            'name' => $r->name,
                            'delivered_orders' => $r->orders_count,
                        ])
                        ->all(),
                ],
                message: 'Platform stats fetched successfully',
            );
        });
    }
}
