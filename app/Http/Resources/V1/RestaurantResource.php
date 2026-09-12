<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Services\Media\ImageStorageService;
use App\Services\Restaurant\OpeningHoursService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Restaurant
 */
class RestaurantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hours = app(OpeningHoursService::class);
        $images = app(ImageStorageService::class);
        $nextOpensAt = $hours->nextOpensAt($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,

            'logo_path' => $this->logo_path ? $images->url($this->logo_path) : null,
            'cover_path' => $this->cover_path ? $images->url($this->cover_path) : null,

            'phone' => $this->phone,
            'email' => $this->email,

            'address' => [
                'line' => $this->address_line,
                'landmark' => $this->landmark,
                'city' => $this->city,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
                'country' => $this->country,
            ],

            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'delivery_radius_km' => (float) $this->delivery_radius_km,

            // Money is exposed as a fixed-point string plus its raw minor units,
            // so a client can display it without ever parsing a float.
            'min_order_amount' => $this->min_order_amount,
            'delivery_fee_base' => $this->delivery_fee_base,
            'delivery_fee_per_km' => $this->delivery_fee_per_km,
            'packaging_fee' => $this->packaging_fee,
            'tax_percentage' => (float) $this->tax_percentage,
            'currency' => config('delivery.currency'),

            'avg_prep_time_minutes' => $this->avg_prep_time_minutes,
            'timezone' => $this->timezone,

            'status' => $this->status->value,
            'is_accepting_orders' => $this->is_accepting_orders,
            'is_open' => $hours->isOpen($this->resource),
            'next_opens_at' => $nextOpensAt?->toIso8601String(),
            'todays_hours' => $hours->todaysSlots($this->resource),

            'hours' => RestaurantHourResource::collection($this->whenLoaded('hours')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
