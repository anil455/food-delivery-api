<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Support\Money;

/**
 * A fully priced cart.
 *
 * Every figure here is computed server-side from the products table at the
 * moment it is asked for. Nothing a client sends ever reaches these fields.
 */
final readonly class PricedCart
{
    /**
     * @param  array<int, PricedCartLine>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
        public Money $discount,
        public Money $deliveryFee,
        public Money $packagingFee,
        public Money $tax,
        public Money $grandTotal,
        public ?float $distanceKm,
        public ?int $estimatedMinutes,
        public ?string $couponCode,
        public bool $meetsMinimumOrder,
        public Money $minimumOrderAmount,
    ) {}

    public function toArray(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'delivery_fee' => $this->deliveryFee,
            'packaging_fee' => $this->packagingFee,
            'tax' => $this->tax,
            'grand_total' => $this->grandTotal,
            'currency' => config('delivery.currency'),
            'distance_km' => $this->distanceKm,
            'estimated_minutes' => $this->estimatedMinutes,
            'coupon_code' => $this->couponCode,
            'meets_minimum_order' => $this->meetsMinimumOrder,
            'minimum_order_amount' => $this->minimumOrderAmount,
        ];
    }
}
