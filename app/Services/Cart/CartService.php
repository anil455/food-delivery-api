<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\CartException;
use App\Exceptions\Api\CartRestaurantMismatchException;
use App\Models\Addon;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Tenancy\RestaurantContext;
use Illuminate\Support\Facades\DB;

/**
 * Cart writes.
 *
 * The single-restaurant rule is enforced in three independent places:
 *   - the schema: one cart per user, and the cart carries the restaurant
 *   - this service: an explicit conflict check before anything is written
 *   - the database: composite foreign keys reject a cross-tenant cart_item row
 *
 * The service check exists to produce a helpful 409 rather than a constraint
 * violation, not because the other two are optional.
 */
final class CartService
{
    public function __construct(private readonly RestaurantContext $context) {}

    /** The cart for this user, created against the given restaurant if absent. */
    public function forUser(User $user, Restaurant $restaurant): Cart
    {
        $cart = $this->existing($user);

        if ($cart !== null) {
            return $cart;
        }

        return $this->createFor($user, $restaurant);
    }

    public function existing(User $user): ?Cart
    {
        $cart = Cart::query()->where('user_id', $user->getKey())->first();

        if ($cart === null) {
            return null;
        }

        /*
         * Load the contents under the tenant the cart actually belongs to.
         *
         * Products, variants and add-ons are tenant-scoped, and a cart may hold
         * items from a restaurant the customer is no longer browsing. The
         * fail-closed scope reports that as a missing context rather than
         * quietly serving another restaurant rows, so the tenant is named here
         * explicitly.
         */
        return $this->context->runForRestaurant(
            (int) $cart->restaurant_id,
            fn (): Cart => $cart->load([
                'items.product', 'items.variant', 'items.addons.addon', 'coupon', 'restaurant',
            ]),
        );
    }

    /**
     * Add a product to the cart.
     *
     * @param  array<int, array{addon_id: int, quantity?: int}>  $addons
     *
     * @throws ApiException
     */
    public function addItem(
        User $user,
        Restaurant $restaurant,
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        array $addons = [],
        ?string $instructions = null,
        bool $replaceCart = false,
    ): CartItem {
        $this->assertOrderable($product, $variant);

        /*
         * Adding to a cart is always an action against one named restaurant, so
         * this declares that tenant itself rather than relying on the calling
         * route to have set it. Reorder, for one, has no tenant middleware: the
         * restaurant comes from the past order, not from what the customer
         * happens to be browsing.
         */
        return $this->context->runForRestaurant($restaurant, fn (): CartItem => DB::transaction(function () use (
            $user, $restaurant, $product, $variant, $quantity, $addons, $instructions, $replaceCart
        ): CartItem {
            $cart = Cart::query()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                $cart = $this->createFor($user, $restaurant);
            } elseif (! $cart->belongsToRestaurant($restaurant)) {
                $this->handleRestaurantSwitch($cart, $restaurant, $replaceCart);
            }

            $resolvedAddons = $this->resolveAddons($product, $addons);

            $item = $cart->items()->create([
                'restaurant_id' => $restaurant->getKey(),
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant?->getKey(),
                'quantity' => $quantity,
                'price_at_add' => $product->priceFor($variant),
                'special_instructions' => $instructions,
            ]);

            foreach ($resolvedAddons as $addon) {
                $item->addons()->create([
                    'restaurant_id' => $restaurant->getKey(),
                    'addon_id' => $addon['addon']->getKey(),
                    'quantity' => $addon['quantity'],
                    'price_at_add' => $addon['addon']->price,
                ]);
            }

            return $item->load(['product', 'variant', 'addons.addon']);
        }));
    }

    public function updateQuantity(Cart $cart, int $itemId, int $quantity): void
    {
        $item = $cart->items()->findOrFail($itemId);

        if ($quantity <= 0) {
            $item->delete();

            return;
        }

        $item->forceFill(['quantity' => $quantity])->save();
    }

    public function removeItem(Cart $cart, int $itemId): void
    {
        $cart->items()->findOrFail($itemId)->delete();
    }

    /** Empties the cart but keeps the row, so the customer keeps one cart. */
    public function clear(Cart $cart): void
    {
        DB::transaction(function () use ($cart): void {
            $cart->items()->delete();
            $cart->forceFill(['coupon_id' => null])->save();
        });
    }

    /**
     * @throws ApiException
     */
    public function applyCoupon(Cart $cart, User $user, string $code): Coupon
    {
        $coupon = Coupon::query()
            ->usableAt($cart->restaurant_id)
            ->live()
            ->where('code', $code)
            ->first();

        if ($coupon === null) {
            throw CartException::coupon('This code is not valid for this restaurant.');
        }

        if ($coupon->hasReachedGlobalLimit()) {
            throw CartException::coupon('This code has been fully redeemed.');
        }

        if ($coupon->hasReachedUserLimit($user)) {
            throw CartException::coupon('You have already used this code.');
        }

        $cart->forceFill(['coupon_id' => $coupon->getKey()])->save();

        return $coupon;
    }

    public function removeCoupon(Cart $cart): void
    {
        $cart->forceFill(['coupon_id' => null])->save();
    }

    /**
     * user_id is guarded on the model so no request can ever aim a cart at
     * another customer. Creation therefore sets it explicitly.
     */
    private function createFor(User $user, Restaurant $restaurant): Cart
    {
        $cart = new Cart;

        $cart->forceFill([
            'user_id' => $user->getKey(),
            'restaurant_id' => $restaurant->getKey(),
        ])->save();

        return $cart->load('restaurant');
    }

    /**
     * A cart may only ever hold one restaurant.
     *
     * With replace_cart the customer has explicitly agreed to start over; the
     * items are cleared and the cart moves. Without it, the request is refused
     * with everything the client needs to ask the question.
     *
     * @throws CartRestaurantMismatchException
     */
    private function handleRestaurantSwitch(Cart $cart, Restaurant $restaurant, bool $replaceCart): void
    {
        $itemCount = $cart->items()->count();

        if ($itemCount > 0 && ! $replaceCart) {
            throw new CartRestaurantMismatchException(
                currentRestaurantId: (int) $cart->restaurant_id,
                currentRestaurantName: (string) ($cart->restaurant?->name ?? 'another restaurant'),
                requestedRestaurantId: (int) $restaurant->getKey(),
                requestedRestaurantName: (string) $restaurant->name,
                cartItemCount: $itemCount,
            );
        }

        $cart->items()->delete();
        $cart->forceFill([
            'restaurant_id' => $restaurant->getKey(),
            'coupon_id' => null,
        ])->save();

        $cart->load('restaurant');
    }

    /**
     * @throws ApiException
     */
    private function assertOrderable(Product $product, ?ProductVariant $variant): void
    {
        if (! $product->is_active || ! $product->is_available) {
            throw CartException::productUnavailable();
        }

        if ($variant !== null && ! $variant->is_available) {
            throw CartException::productUnavailable('This option is currently unavailable.');
        }
    }

    /**
     * Validate the requested add-ons against the groups actually attached to
     * this product, and against each group min and max selection rule.
     *
     * The client enforces these for usability. The server enforces them because
     * the client is not trusted.
     *
     * @param  array<int, array{addon_id: int, quantity?: int}>  $addons
     * @return array<int, array{addon: Addon, quantity: int}>
     *
     * @throws ApiException
     */
    private function resolveAddons(Product $product, array $addons): array
    {
        $groups = $product->addonGroups()->with('addons')->get();

        $allowed = $groups->flatMap(fn ($group) => $group->addons)->keyBy('id');

        $resolved = [];
        $perGroup = [];

        foreach ($addons as $requested) {
            $addonId = (int) ($requested['addon_id'] ?? 0);
            $addon = $allowed->get($addonId);

            if ($addon === null) {
                throw CartException::invalidAddon(
                    'One of the selected options is not available for this item.'
                );
            }

            if (! $addon->is_available) {
                throw CartException::productUnavailable(
                    "The option \"{$addon->name}\" is currently unavailable."
                );
            }

            $quantity = max(1, (int) ($requested['quantity'] ?? 1));

            $resolved[] = ['addon' => $addon, 'quantity' => $quantity];
            $perGroup[$addon->addon_group_id] = ($perGroup[$addon->addon_group_id] ?? 0) + 1;
        }

        foreach ($groups as $group) {
            $selected = $perGroup[$group->id] ?? 0;

            if ($group->is_required && $selected < max(1, $group->min_select)) {
                throw CartException::invalidAddon(
                    "Choose at least {$group->min_select} option from \"{$group->name}\"."
                );
            }

            if ($group->max_select > 0 && $selected > $group->max_select) {
                throw CartException::invalidAddon(
                    "Choose at most {$group->max_select} option from \"{$group->name}\"."
                );
            }
        }

        return $resolved;
    }

}
