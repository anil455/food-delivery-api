<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Restaurant;
use App\Services\Geo\GeoPoint;
use App\Services\Geo\HaversineCalculator;
use App\Support\Money;

/**
 * Turns a cart into money.
 *
 * This class is the single source of truth for what an order costs. The order
 * placement service calls it again at checkout rather than trusting anything the
 * cart screen displayed, so a stale client, a tampered payload and a genuinely
 * changed price all converge on the same server-computed figure.
 *
 * Tax is applied after the discount, on the discounted subtotal, which is the
 * usual Indian GST treatment for a food order.
 */
final class CartPricingService
{
    public function __construct(private readonly HaversineCalculator $haversine) {}

    public function price(Cart $cart, Restaurant $restaurant, ?Address $deliveryAddress = null): PricedCart
    {
        $lines = $this->priceLines($cart, $restaurant);

        $subtotal = $this->sum(array_map(fn (PricedCartLine $line): Money => $line->lineSubtotal, $lines));

        $coupon = $cart->coupon;
        $discount = $coupon !== null ? $coupon->discountFor($subtotal) : Money::zero();

        // Tax is charged per line so that products with their own rate are
        // handled correctly, then scaled down by whatever the discount removed.
        $tax = $this->taxAfterDiscount($lines, $subtotal, $discount);

        $distanceKm = $this->distanceTo($restaurant, $deliveryAddress);
        $deliveryFee = $this->deliveryFee($restaurant, $distanceKm);
        $packagingFee = $restaurant->packaging_fee ?? Money::zero();

        $grandTotal = $subtotal
            ->subtract($discount)
            ->add($tax, $deliveryFee, $packagingFee)
            ->clampAtZero();

        $minimumOrder = $restaurant->min_order_amount ?? Money::zero();

        return new PricedCart(
            lines: $lines,
            subtotal: $subtotal,
            discount: $discount,
            deliveryFee: $deliveryFee,
            packagingFee: $packagingFee,
            tax: $tax,
            grandTotal: $grandTotal,
            distanceKm: $distanceKm,
            estimatedMinutes: $this->estimatedMinutes($restaurant, $distanceKm),
            couponCode: $coupon?->code,
            // Compared against the subtotal before fees: a customer should not
            // clear a minimum by paying more delivery.
            meetsMinimumOrder: ! $subtotal->lessThan($minimumOrder),
            minimumOrderAmount: $minimumOrder,
        );
    }

    /**
     * @return array<int, PricedCartLine>
     */
    private function priceLines(Cart $cart, Restaurant $restaurant): array
    {
        $lines = [];

        foreach ($cart->items as $item) {
            $product = $item->product;
            $variant = $item->variant;

            // Live price from the products table, never price_at_add.
            $unitPrice = $product->priceFor($variant);

            $addons = [];
            $addonsTotal = Money::zero();

            foreach ($item->addons as $cartAddon) {
                $addon = $cartAddon->addon;

                if ($addon === null) {
                    continue;
                }

                $addonLine = $addon->price->multiply($cartAddon->quantity);
                $addonsTotal = $addonsTotal->add($addonLine);

                $addons[] = [
                    'addon' => $addon,
                    'quantity' => $cartAddon->quantity,
                    'line_total' => $addonLine,
                ];
            }

            $lineSubtotal = $unitPrice->add($addonsTotal)->multiply($item->quantity);
            $taxPercentage = $product->effectiveTaxPercentage($restaurant);
            $taxAmount = $lineSubtotal->percentage($taxPercentage);

            $lines[] = new PricedCartLine(
                item: $item,
                unitPrice: $unitPrice,
                quantity: $item->quantity,
                addons: $addons,
                addonsTotal: $addonsTotal,
                lineSubtotal: $lineSubtotal,
                taxAmount: $taxAmount,
                lineTotal: $lineSubtotal->add($taxAmount),
                taxPercentage: $taxPercentage,
                priceChanged: ! $unitPrice->equals($item->price_at_add),
            );
        }

        return $lines;
    }

    /**
     * Scale the per-line tax by the proportion of the subtotal that survived
     * the discount, so a discount reduces tax rather than being taxed.
     *
     * @param  array<int, PricedCartLine>  $lines
     */
    private function taxAfterDiscount(array $lines, Money $subtotal, Money $discount): Money
    {
        $grossTax = $this->sum(array_map(fn (PricedCartLine $line): Money => $line->taxAmount, $lines));

        if ($discount->isZero() || $subtotal->isZero()) {
            return $grossTax;
        }

        $taxable = $subtotal->subtract($discount)->clampAtZero();

        // Integer arithmetic throughout: no float ever touches a tax figure.
        return Money::fromMinor(
            (int) round($grossTax->minor * $taxable->minor / $subtotal->minor, 0, PHP_ROUND_HALF_UP)
        );
    }

    private function distanceTo(Restaurant $restaurant, ?Address $address): ?float
    {
        if ($address === null) {
            return null;
        }

        return round($this->haversine->kilometresBetween(
            new GeoPoint((float) $restaurant->latitude, (float) $restaurant->longitude),
            new GeoPoint((float) $address->latitude, (float) $address->longitude),
        ), 2);
    }

    private function deliveryFee(Restaurant $restaurant, ?float $distanceKm): Money
    {
        $base = $restaurant->delivery_fee_base ?? Money::zero();

        if ($distanceKm === null) {
            return $base;
        }

        $perKm = $restaurant->delivery_fee_per_km ?? Money::zero();

        // Charged per started kilometre, which is both simpler to explain to a
        // customer and never under-charges the rider.
        return $base->add($perKm->multiply((int) ceil($distanceKm)));
    }

    private function estimatedMinutes(Restaurant $restaurant, ?float $distanceKm): ?int
    {
        if ($distanceKm === null) {
            return null;
        }

        $speed = (float) config('delivery.average_speed_kmph');
        $travel = $speed > 0.0 ? ($distanceKm / $speed) * 60 : 0.0;

        return (int) ceil((int) $restaurant->avg_prep_time_minutes + $travel);
    }

    /**
     * @param  array<int, Money>  $amounts
     */
    private function sum(array $amounts): Money
    {
        return array_reduce(
            $amounts,
            static fn (Money $carry, Money $amount): Money => $carry->add($amount),
            Money::zero(),
        );
    }
}
