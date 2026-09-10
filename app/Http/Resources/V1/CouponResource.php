<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\CouponType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Coupon
 */
class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Null means a platform-wide coupon, usable at every restaurant.
            'restaurant_id' => $this->restaurant_id,
            'code' => $this->code,
            'description' => $this->description,

            'type' => $this->type->value,
            // A percent coupon holds a whole-percent rate; a fixed coupon holds
            // minor units. Exposed raw so an admin UI can edit either.
            'value' => (int) $this->value,
            'max_discount' => $this->max_discount,
            'min_order_amount' => $this->min_order_amount,

            'usage_limit' => $this->usage_limit,
            'per_user_limit' => $this->per_user_limit,
            'times_used' => (int) $this->times_used,

            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'is_live' => $this->isLive(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
