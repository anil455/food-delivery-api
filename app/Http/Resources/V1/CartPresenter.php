<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Cart;
use App\Services\Cart\PricedCart;
use App\Services\Cart\PricedCartLine;

/**
 * Shapes a cart plus its server-computed pricing into one response body.
 *
 * A presenter rather than a JsonResource because the payload is a join of two
 * things: the stored cart, and a PricedCart that exists only for this request.
 */
final class CartPresenter
{
    public static function make(Cart $cart, PricedCart $priced): array
    {
        return [
            'id' => $cart->id,
            'restaurant' => [
                'id' => $cart->restaurant_id,
                'name' => $cart->restaurant?->name,
                'slug' => $cart->restaurant?->slug,
            ],
            'items' => array_map(self::line(...), $priced->lines),
            'item_count' => array_sum(array_map(
                static fn (PricedCartLine $line): int => $line->quantity,
                $priced->lines,
            )),
            'totals' => $priced->toArray(),
        ];
    }

    /** An empty cart still returns a full, well-formed body. */
    public static function empty(): array
    {
        return [
            'id' => null,
            'restaurant' => null,
            'items' => [],
            'item_count' => 0,
            'totals' => null,
        ];
    }

    private static function line(PricedCartLine $line): array
    {
        return [
            'id' => $line->item->id,
            'product' => [
                'id' => $line->item->product_id,
                'name' => $line->item->product?->name,
                'is_veg' => $line->item->product?->is_veg,
                'image_path' => $line->item->product?->image_path,
            ],
            'variant' => $line->item->variant === null ? null : [
                'id' => $line->item->variant->id,
                'name' => $line->item->variant->name,
            ],
            'quantity' => $line->quantity,
            'unit_price' => $line->unitPrice,
            'addons' => array_map(static fn (array $addon): array => [
                'id' => $addon['addon']->id,
                'name' => $addon['addon']->name,
                'quantity' => $addon['quantity'],
                'line_total' => $addon['line_total'],
            ], $line->addons),
            'addons_total' => $line->addonsTotal,
            'line_subtotal' => $line->lineSubtotal,
            'tax_amount' => $line->taxAmount,
            'line_total' => $line->lineTotal,
            'special_instructions' => $line->item->special_instructions,

            // Lets the cart screen say the price moved rather than silently
            // charging something other than what the customer last saw.
            'price_changed' => $line->priceChanged,
        ];
    }
}
