<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProductVariant
 */
class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Absolute price: a variant replaces the base price, never adjusts it.
            'price' => $this->price,
            'is_default' => $this->is_default,
            'is_available' => $this->is_available,
        ];
    }
}
