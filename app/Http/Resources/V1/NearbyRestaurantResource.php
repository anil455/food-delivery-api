<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Services\Media\ImageStorageService;
use App\Services\Restaurant\OpeningHoursService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The listing shape for nearby search.
 *
 * Deliberately lighter than RestaurantResource: a listing screen renders dozens
 * of these, and the full record carries fields no card ever shows.
 *
 * @mixin \App\Models\Restaurant
 */
class NearbyRestaurantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hours = app(OpeningHoursService::class);
        $images = app(ImageStorageService::class);

        $distance = round((float) $this->distance_km, 2);
        $isOpen = $hours->isOpen($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'logo_path' => $this->logo_path ? $images->url($this->logo_path) : null,

            'distance' => $distance,
            'distance_unit' => 'km',

            /*
             * Two separate facts, and clients conflate them constantly:
             * `is_open` is about the clock, `delivers_to_you` is about whether
             * this address falls inside the delivery radius. A restaurant can be
             * open and still unable to deliver here.
             */
            'is_open' => $isOpen,
            'is_accepting_orders' => $this->is_accepting_orders,
            'delivers_to_you' => $distance <= (float) $this->delivery_radius_km,

            'next_opens_at' => $isOpen ? null : $hours->nextOpensAt($this->resource)?->toIso8601String(),

            'min_order_amount' => $this->min_order_amount,
            'delivery_fee_base' => $this->delivery_fee_base,
            'estimated_delivery_minutes' => $this->estimatedMinutes($distance),

            'city' => $this->city,
            'currency' => config('delivery.currency'),
        ];
    }

    /**
     * Prep time plus travel time at a deliberately conservative riding speed.
     * Under-promising costs nothing; over-promising costs a refund.
     */
    private function estimatedMinutes(float $distanceKm): int
    {
        $speed = (float) config('delivery.average_speed_kmph');

        $travel = $speed > 0.0 ? ($distanceKm / $speed) * 60 : 0.0;

        return (int) ceil((int) $this->avg_prep_time_minutes + $travel);
    }
}
