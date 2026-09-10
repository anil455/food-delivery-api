<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrderItem
 */
class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Snapshot values, not live product data.
            'product_name' => $this->product_name,
            'variant_name' => $this->variant_name,
            'is_veg' => $this->is_veg,
            'unit_price' => $this->unit_price,
            'quantity' => $this->quantity,
            'addons_total' => $this->addons_total,
            'line_subtotal' => $this->line_subtotal,
            'tax_amount' => $this->tax_amount,
            'line_total' => $this->line_total,
            'special_instructions' => $this->special_instructions,
            'product_id' => $this->product_id,
            'addons' => $this->whenLoaded('addons', fn () => $this->addons->map(fn ($addon): array => [
                'name' => $addon->addon_name,
                'unit_price' => $addon->unit_price,
                'quantity' => $addon->quantity,
                'line_total' => $addon->line_total,
            ])->values()->all()),
        ];
    }
}
