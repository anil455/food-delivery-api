<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_path' => $this->image_path,

            'base_price' => $this->base_price,
            'compare_at_price' => $this->compare_at_price,
            'currency' => config('delivery.currency'),

            'is_veg' => $this->is_veg,
            'spice_level' => $this->spice_level,
            'prep_time_minutes' => $this->prep_time_minutes,

            'is_available' => $this->is_available,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,

            'category' => new CategoryResource($this->whenLoaded('category')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'addon_groups' => AddonGroupResource::collection($this->whenLoaded('addonGroups')),
        ];
    }
}
