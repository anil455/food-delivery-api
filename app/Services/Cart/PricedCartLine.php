<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\CartItem;
use App\Support\Money;

/**
 * One priced cart line, with the add-on total already folded in.
 *
 * `priceChanged` exists so the cart screen can say "the price of this item has
 * changed since you added it" rather than silently charging a different amount
 * than the customer last saw.
 */
final readonly class PricedCartLine
{
    /**
     * @param  array<int, array{addon: \App\Models\Addon, quantity: int, line_total: Money}>  $addons
     */
    public function __construct(
        public CartItem $item,
        public Money $unitPrice,
        public int $quantity,
        public array $addons,
        public Money $addonsTotal,
        public Money $lineSubtotal,
        public Money $taxAmount,
        public Money $lineTotal,
        public float $taxPercentage,
        public bool $priceChanged,
    ) {}
}
