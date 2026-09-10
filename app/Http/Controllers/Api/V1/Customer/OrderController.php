<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\ApiErrorCode;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Customer\PlaceOrderRequest;
use App\Http\Resources\V1\OrderResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Services\Cart\CartService;
use App\Services\Order\OrderPlacementService;
use App\Services\Order\OrderStatusService;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderPlacementService $placement,
        private readonly OrderStatusService $statuses,
        private readonly CartService $carts,
        private readonly RestaurantContext $context,
    ) {}

    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $idempotencyKey = $request->header('Idempotency-Key');

        // Before anything else: a retry of an order that already succeeded gets
        // that order back. The cart is empty by then, so checking it first would
        // answer a retry with CART_EMPTY.
        $replayed = $this->placement->findByIdempotencyKey($user, $idempotencyKey);

        if ($replayed !== null) {
            return ApiResponse::created(new OrderResource($replayed), 'Order placed successfully');
        }

        $cart = $this->carts->existing($user);

        if ($cart === null || $cart->items->isEmpty()) {
            return ApiResponse::error(
                message: 'Your cart is empty.',
                code: ApiErrorCode::CartEmpty,
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $address = $user->addresses()->findOrFail($validated['address_id']);

        // The order is placed against the cart restaurant, not the browsing
        // context, so switching store mid-checkout cannot redirect an order.
        $restaurant = $this->context->runCrossTenant(
            fn () => Restaurant::query()->with(['hours', 'holidays'])->findOrFail($cart->restaurant_id)
        );

        $order = $this->placement->place(
            user: $user,
            cart: $cart,
            restaurant: $restaurant,
            address: $address,
            paymentMethod: $validated['payment_method'],
            idempotencyKey: $idempotencyKey,
            instructions: $validated['special_instructions'] ?? null,
            tipMinorUnits: (int) ($validated['tip'] ?? 0),
        );

        return ApiResponse::created(
            new OrderResource($order->load(['items.addons', 'restaurant'])),
            'Order placed successfully',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        // Order history spans every store the customer has used, so the tenant
        // scope is lifted here and replaced by an explicit user_id filter on the
        // very next line.
        $orders = Order::withoutTenantScope()
            ->forUser($request->user())
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with(['restaurant', 'items'])
            ->latest('id')
            ->paginate($validated['per_page'] ?? 15);

        return ApiResponse::paginated(
            OrderResource::collection($orders),
            'Orders fetched successfully',
        );
    }

    public function show(Request $request, int $order): JsonResponse
    {
        return ApiResponse::success(
            data: new OrderResource($this->ownedOrFail($request, $order)),
            message: 'Order fetched successfully',
        );
    }

    public function cancel(Request $request, int $order): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $model = $this->ownedOrFail($request, $order);

        // A customer may only cancel before the kitchen has committed; after
        // that they have to call the restaurant.
        if (! $model->status->isCancellableByCustomer()) {
            return ApiResponse::error(
                message: 'This order can no longer be cancelled. Please contact the restaurant.',
                code: ApiErrorCode::InvalidStatusTransition,
                status: Response::HTTP_CONFLICT,
                data: ['current_status' => $model->status->value],
            );
        }

        $this->statuses->transition(
            $model,
            OrderStatus::Cancelled,
            $request->user(),
            $validated['reason'] ?? 'Cancelled by customer',
        );

        return ApiResponse::success(
            data: new OrderResource($model->fresh(['items.addons', 'restaurant'])),
            message: 'Order cancelled successfully',
        );
    }

    public function track(Request $request, int $order): JsonResponse
    {
        $model = $this->ownedOrFail($request, $order);

        return ApiResponse::success(
            data: [
                'order_number' => $model->order_number,
                'status' => $model->status->value,
                'status_label' => $model->status->label(),
                'is_terminal' => $model->status->isTerminal(),
                'estimated_minutes' => $model->estimated_minutes,
                'placed_at' => $model->placed_at?->toIso8601String(),
                'confirmed_at' => $model->confirmed_at?->toIso8601String(),
                'delivered_at' => $model->delivered_at?->toIso8601String(),
                'history' => $model->statusHistories
                    ->map(fn ($entry): array => [
                        'to_status' => $entry->to_status->value,
                        'label' => $entry->to_status->label(),
                        'note' => $entry->note,
                        'at' => $entry->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ],
            message: 'Order tracking fetched successfully',
        );
    }

    /**
     * Rebuild a cart from a past order.
     *
     * Deliberately best-effort: menus change, so items that have since been
     * removed, deactivated or sold out are skipped and reported rather than
     * failing the whole request. Prices come from the products table, never
     * from the old order, so a reorder is never a way to lock in a stale price.
     */
    public function reorder(Request $request, int $order): JsonResponse
    {
        $source = $this->ownedOrFail($request, $order);
        $user = $request->user();

        $restaurant = $this->context->runCrossTenant(
            fn () => Restaurant::query()->find($source->restaurant_id)
        );

        if ($restaurant === null || ! $restaurant->isActive()) {
            return ApiResponse::error(
                message: 'This restaurant is no longer available.',
                code: ApiErrorCode::RestaurantNotAccessible,
                status: Response::HTTP_CONFLICT,
            );
        }

        $added = 0;
        $skipped = [];

        foreach ($source->items as $item) {
            $product = $item->product_id === null ? null : $this->context->runForRestaurant(
                (int) $restaurant->getKey(),
                fn () => Product::query()->find($item->product_id)
            );

            if ($product === null || ! $product->is_active || ! $product->is_available) {
                $skipped[] = $item->product_name;

                continue;
            }

            $this->carts->addItem(
                user: $user,
                restaurant: $restaurant,
                product: $product,
                variant: null,
                quantity: $item->quantity,
                addons: [],
                instructions: $item->special_instructions,
                // The customer asked for this order again, which is an explicit
                // enough intent to replace whatever was in the cart.
                replaceCart: $added === 0,
            );

            $added++;
        }

        if ($added === 0) {
            return ApiResponse::error(
                message: 'None of the items from that order are available right now.',
                code: ApiErrorCode::ProductUnavailable,
                status: Response::HTTP_CONFLICT,
                data: ['skipped' => $skipped],
            );
        }

        return ApiResponse::success(
            data: [
                'restaurant_id' => $restaurant->getKey(),
                'items_added' => $added,
                'items_skipped' => $skipped,
            ],
            message: $skipped === []
                ? 'Items added to your cart'
                : 'Some items are no longer available and were skipped',
        );
    }

    /**
     * Orders are addressed by id but always looked up with an explicit user
     * filter, so another customer order id resolves to a 404 rather than data.
     */
    private function ownedOrFail(Request $request, int $orderId): Order
    {
        return Order::withoutTenantScope()
            ->forUser($request->user())
            ->with(['items.addons', 'restaurant', 'statusHistories'])
            ->findOrFail($orderId);
    }
}
