<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Services\Media\ImageStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Banner
 */
class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $images = app(ImageStorageService::class);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'image_path' => $this->image_path ? $images->url($this->image_path) : null,

            'link_type' => $this->link_type,
            'link_value' => $this->link_value,

            // Present only for a restaurant's own promotion, never for a
            // platform banner.
            'restaurant' => $this->when($this->restaurant_id !== null, fn (): array => [
                'id' => $this->restaurant_id,
                'name' => $this->whenLoaded('restaurant', fn () => $this->restaurant->name),
                'slug' => $this->whenLoaded('restaurant', fn () => $this->restaurant->slug),
            ]),

            // Only present when BannerFinder computed it (the public,
            // location-aware listing), not on the plain admin CRUD endpoints.
            'distance_km' => $this->when(
                isset($this->distance_km),
                fn () => round((float) $this->distance_km, 2)
            ),

            'sort_order' => $this->sort_order,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->is_active,
        ];
    }
}
