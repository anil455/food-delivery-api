<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\ApiErrorCode;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\CartException;
use App\Exceptions\Api\OrderException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\NewOrderReceived;
use App\Services\Cart\CartPricingService;
use App\Services\Cart\PricedCart;
use App\Services\Cart\PricedCartLine;
use App\Services\Restaurant\OpeningHoursService;
use App\Services\Tenancy\RestaurantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Turning a cart into an order.
 *
 * The whole operation runs in one transaction with the cart row locked, and
 * every figure is recomputed here from the products table. Nothing the client
 * sent about price, tax or totals is read at any point.
 */
final class OrderPlacementService
{
    public function __construct(
        private readonly CartPricingService $pricing,
        private readonly OrderNumberGenerator $numbers,
        private readonly OpeningHoursService $hours,
        private readonly OrderStatusService $statuses,
        private readonly RestaurantContext $context,
    ) {}

    /**
     * An order already placed under this idempotency key, if any.
     *
     * Callers must consult this before any cart validation. After a successful
     * order the cart is empty, so a retried request would otherwise be rejected
     * as an empty cart instead of returning the order it already created, which
     * defeats the entire point of the key.
     */
    public function findByIdempotencyKey(User $user, ?string $idempotencyKey): ?Order
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        return Order::withoutTenantScope()
            ->where('user_id', $user->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->with(['items.addons', 'restaurant'])
            ->first();
    }

    /**
     * @throws ApiException
     */
    public function place(
        User $user,
        Cart $cart,
        Restaurant $restaurant,
        Address $address,
        string $paymentMethod,
        ?string $idempotencyKey = null,
        ?string $instructions = null,
        int $tipMinorUnits = 0,
    ): Order {
        // A retried request must return the original order, not create a second.
        $existing = $this->findByIdempotencyKey($user, $idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $this->assertRestaurantCanAccept($restaurant);

        try {
            $order = DB::transaction(function () use (
                $user, $cart, $restaurant, $address, $paymentMethod, $idempotencyKey, $instructions, $tipMinorUnits
            ): Order {
                // Locking the cart stops a double submit from placing two orders
                // out of the same basket.
                $locked = Cart::query()->whereKey($cart->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    throw CartException::empty();
                }

                // Same reasoning as CartService::existing(): the cart names
                // its own tenant, which the scoped item relations then need.
                $this->context->runForRestaurant(
                    (int) $locked->restaurant_id,
                    fn () => $locked->load([
                        'items.product', 'items.variant', 'items.addons.addon', 'coupon', 'restaurant',
                    ]),
                );

                if ($locked->items->isEmpty()) {
                    throw CartException::empty();
                }

                $priced = $this->pricing->price($locked, $restaurant, $address);

                if (! $priced->meetsMinimumOrder) {
                    throw CartException::minimumNotMet($priced->minimumOrderAmount->toMajorString());
                }

                $order = $this->createOrder(
                    $user, $restaurant, $address, $priced, $locked,
                    $paymentMethod, $idempotencyKey, $instructions, $tipMinorUnits,
                );

                $this->createItems($order, $priced);
                $this->redeemCoupon($order, $locked, $user, $priced);

                $this->statuses->record($order, null, OrderStatus::Pending, $user, 'Order placed');

                // The cart survives as an empty shell: one cart per user, always.
                $locked->items()->delete();
                $locked->forceFill(['coupon_id' => null])->save();

                return $order->load(['items.addons', 'restaurant']);
            });

            // After the commit, so the kitchen never gets a text about an order
            // a rollback then erased.
            Notification::route('sms', $restaurant->phone)
                ->notify(new NewOrderReceived($order));

            return $order;
        } catch (UniqueConstraintViolationException $e) {
            // Two concurrent submits with the same key: the loser reads the
            // winner rather than surfacing a database error.
            $existing = $this->findByIdempotencyKey($user, $idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * @throws ApiException
     */
    private function assertRestaurantCanAccept(Restaurant $restaurant): void
    {
        if (! $restaurant->isActive()) {
            throw new OrderException(
                'This restaurant is not currently available.',
                ApiErrorCode::RestaurantNotAccessible,
                Response::HTTP_FORBIDDEN,
            );
        }

        if (! $restaurant->is_accepting_orders) {
            throw new OrderException(
                'This restaurant has paused new orders.',
                ApiErrorCode::RestaurantNotAcceptingOrders,
                Response::HTTP_CONFLICT,
            );
        }

        if (! $this->hours->isOpen($restaurant)) {
            $next = $this->hours->nextOpensAt($restaurant);

            throw new OrderException(
                'This restaurant is closed right now.',
                ApiErrorCode::RestaurantClosed,
                Response::HTTP_CONFLICT,
                ['next_opens_at' => $next?->toIso8601String()],
            );
        }
    }

    private function createOrder(
        User $user,
        Restaurant $restaurant,
        Address $address,
        PricedCart $priced,
        Cart $cart,
        string $paymentMethod,
        ?string $idempotencyKey,
        ?string $instructions,
        int $tipMinorUnits,
    ): Order {
        if ($priced->distanceKm !== null && ! $restaurant->deliversWithin($priced->distanceKm)) {
            throw new OrderException(
                'This restaurant does not deliver to the selected address.',
                ApiErrorCode::OutsideDeliveryRadius,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['distance_km' => $priced->distanceKm, 'delivery_radius_km' => (float) $restaurant->delivery_radius_km],
            );
        }

        $tip = \App\Support\Money::fromMinor(max(0, $tipMinorUnits));
        $grandTotal = $priced->grandTotal->add($tip);

        $order = new Order;

        $order->forceFill([
            'order_number' => $this->numbers->generate(),
            'idempotency_key' => $idempotencyKey,
            'user_id' => $user->getKey(),
            'restaurant_id' => $restaurant->getKey(),
            'address_id' => $address->getKey(),

            // The snapshot, not the foreign key, is the record of truth.
            'delivery_address' => [
                'label' => $address->label,
                'address_line1' => $address->address_line1,
                'address_line2' => $address->address_line2,
                'landmark' => $address->landmark,
                'city' => $address->city,
                'state' => $address->state,
                'postal_code' => $address->postal_code,
                'country' => $address->country,
                'latitude' => (float) $address->latitude,
                'longitude' => (float) $address->longitude,
            ],
            'customer_name' => $user->name,
            'customer_phone' => $user->phone,

            'status' => OrderStatus::Pending,
            // Online payments move to paid via the gateway webhook; cash on
            // delivery moves when the rider marks it collected.
            'payment_status' => PaymentStatus::Pending,
            'payment_method' => $paymentMethod,

            'subtotal' => $priced->subtotal,
            'discount_total' => $priced->discount,
            'delivery_fee' => $priced->deliveryFee,
            'packaging_fee' => $priced->packagingFee,
            'tax_total' => $priced->tax,
            'tip' => $tip,
            'grand_total' => $grandTotal,
            'currency' => config('delivery.currency'),

            'coupon_id' => $cart->coupon?->getKey(),
            'coupon_code' => $cart->coupon?->code,

            'distance_km' => $priced->distanceKm,
            'estimated_minutes' => $priced->estimatedMinutes,
            'special_instructions' => $instructions,
            'placed_at' => now(),
        ])->save();

        return $order;
    }

    private function createItems(Order $order, PricedCart $priced): void
    {
        foreach ($priced->lines as $line) {
            $orderItem = $order->items()->create([
                'restaurant_id' => $order->restaurant_id,
                'product_id' => $line->item->product_id,
                'product_variant_id' => $line->item->product_variant_id,

                // Snapshot columns.
                'product_name' => $line->item->product?->name ?? 'Item',
                'variant_name' => $line->item->variant?->name,
                'is_veg' => (bool) ($line->item->product?->is_veg ?? true),
                'unit_price' => $line->unitPrice,
                'quantity' => $line->quantity,
                'addons_total' => $line->addonsTotal,
                'line_subtotal' => $line->lineSubtotal,
                'tax_amount' => $line->taxAmount,
                'line_total' => $line->lineTotal,
                'product_snapshot' => $this->snapshot($line),
                'special_instructions' => $line->item->special_instructions,
            ]);

            foreach ($line->addons as $addon) {
                $orderItem->addons()->create([
                    'restaurant_id' => $order->restaurant_id,
                    'addon_id' => $addon['addon']->getKey(),
                    'addon_name' => $addon['addon']->name,
                    'unit_price' => $addon['addon']->price,
                    'quantity' => $addon['quantity'],
                    'line_total' => $addon['line_total'],
                ]);
            }
        }
    }

    private function snapshot(PricedCartLine $line): array
    {
        $product = $line->item->product;

        return [
            'description' => $product?->description,
            'image_path' => $product?->image_path,
            'spice_level' => $product?->spice_level,
            'tax_percentage' => $line->taxPercentage,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private function redeemCoupon(Order $order, Cart $cart, User $user, PricedCart $priced): void
    {
        $coupon = $cart->coupon;

        if ($coupon === null || $priced->discount->isZero()) {
            return;
        }

        // The unique index on order_id is what actually prevents a double
        // redemption; incrementing here is bookkeeping, not the guard.
        $coupon->redemptions()->create([
            'user_id' => $user->getKey(),
            'order_id' => $order->getKey(),
            'discount_amount' => $priced->discount,
        ]);

        $coupon->increment('times_used');
    }
}
