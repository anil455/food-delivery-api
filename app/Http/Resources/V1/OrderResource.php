<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_terminal' => $this->status->isTerminal(),
            'can_cancel' => $this->status->isCancellableByCustomer(),

            'payment_status' => $this->payment_status->value,
            'payment_method' => $this->payment_method,

            'restaurant' => [
                'id' => $this->restaurant_id,
                'name' => $this->whenLoaded('restaurant', fn () => $this->restaurant->name),
                'slug' => $this->whenLoaded('restaurant', fn () => $this->restaurant->slug),
                'phone' => $this->whenLoaded('restaurant', fn () => $this->restaurant->phone),
            ],

            // The snapshot taken at checkout, not a live join on addresses.
            'delivery_address' => $this->delivery_address,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,

            'items' => OrderItemResource::collection($this->whenLoaded('items')),

            'totals' => [
                'subtotal' => $this->subtotal,
                'discount' => $this->discount_total,
                'delivery_fee' => $this->delivery_fee,
                'packaging_fee' => $this->packaging_fee,
                'tax' => $this->tax_total,
                'tip' => $this->tip,
                'grand_total' => $this->grand_total,
                'currency' => $this->currency,
            ],

            'coupon_code' => $this->coupon_code,
            'distance_km' => $this->distance_km === null ? null : (float) $this->distance_km,
            'estimated_minutes' => $this->estimated_minutes,
            'special_instructions' => $this->special_instructions,

            'placed_at' => $this->placed_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
