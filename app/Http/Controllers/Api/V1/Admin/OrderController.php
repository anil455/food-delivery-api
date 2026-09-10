<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderResource;
use App\Models\Order;
use App\Services\Order\OrderStatusService;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The restaurant order board.
 *
 * Orders are tenant-scoped, so every query here is automatically confined to
 * the restaurant in context. This is the read path that most needed protecting:
 * an order carries the customer name, phone and full delivery address.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderStatusService $statuses,
        private readonly RestaurantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(OrderStatus::values())],
            'active' => ['sometimes', 'boolean'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'search' => ['sometimes', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('active'), fn ($q) => $q->active())
            ->when($validated['date_from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($validated['date_to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($validated['search'] ?? null, fn ($q, $term) => $q->where(
                fn ($inner) => $inner
                    ->where('order_number', 'like', '%'.$term.'%')
                    ->orWhere('customer_phone', 'like', '%'.$term.'%')
            ))
            ->with(['items', 'restaurant'])
            ->latest('id')
            ->paginate($validated['per_page'] ?? 20);

        return ApiResponse::paginated(OrderResource::collection($orders), 'Orders fetched successfully');
    }

    public function show(int $order): JsonResponse
    {
        $model = Order::query()
            ->with(['items.addons', 'restaurant', 'statusHistories.changedBy'])
            ->findOrFail($order);

        $this->authorize('view', $model);

        return ApiResponse::success(new OrderResource($model), 'Order fetched successfully');
    }

    public function updateStatus(Request $request, int $order): JsonResponse
    {
        $model = Order::query()->findOrFail($order);

        $this->authorize('updateStatus', $model);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(OrderStatus::values())],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // The transition table in OrderStatus is the only definition of what is
        // legal; an illegal move returns 409 with the allowed options.
        $this->statuses->transition(
            $model,
            OrderStatus::from($validated['status']),
            $request->user(),
            $validated['note'] ?? null,
        );

        return ApiResponse::success(
            data: new OrderResource($model->fresh(['items.addons', 'restaurant'])),
            message: 'Order status updated successfully',
        );
    }

    /** Counts and revenue for the restaurant dashboard. */
    public function stats(): JsonResponse
    {
        $restaurantId = $this->context->requireId();

        $today = Order::query()->whereDate('created_at', today());

        return ApiResponse::success(
            data: [
                'restaurant_id' => $restaurantId,
                'orders_today' => (clone $today)->count(),
                'active_orders' => Order::query()->active()->count(),
                'pending_orders' => Order::query()->where('status', OrderStatus::Pending->value)->count(),
                'delivered_today' => (clone $today)->where('status', OrderStatus::Delivered->value)->count(),
                'cancelled_today' => (clone $today)->whereIn('status', [
                    OrderStatus::Cancelled->value,
                    OrderStatus::Rejected->value,
                ])->count(),
                'revenue_today' => \App\Support\Money::fromMinor(
                    (int) (clone $today)->where('status', OrderStatus::Delivered->value)->sum('grand_total')
                ),
            ],
            message: 'Dashboard stats fetched successfully',
        );
    }
}
