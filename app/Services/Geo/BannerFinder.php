<?php

declare(strict_types=1);

namespace App\Services\Geo;

use App\Enums\RestaurantStatus;
use App\Models\Banner;
use App\Services\Tenancy\RestaurantContext;
use Illuminate\Support\Collection;

/**
 * Which banners a customer sees: every active platform banner, plus every
 * active restaurant banner whose restaurant can actually deliver to them.
 *
 * The banners table is tiny compared to restaurants, so unlike
 * NearbyRestaurantFinder this filters in PHP with HaversineCalculator rather
 * than pushing the distance expression into SQL — there is no bounding box
 * to optimise around when the candidate set is a handful of rows.
 */
final class BannerFinder
{
    public function __construct(
        private readonly RestaurantContext $context,
        private readonly HaversineCalculator $haversine,
    ) {}

    /** @return Collection<int, Banner> */
    public function find(?GeoPoint $origin): Collection
    {
        return $this->context->runCrossTenant(function () use ($origin) {
            $platform = Banner::query()->active()->platform()->get();

            if ($origin === null) {
                return $platform->sortBy('sort_order')->values();
            }

            $restaurantBanners = Banner::query()
                ->active()
                ->whereNotNull('restaurant_id')
                ->whereHas('restaurant', fn ($q) => $q->where('status', RestaurantStatus::Active->value))
                ->with('restaurant')
                ->get()
                ->filter(function (Banner $banner) use ($origin): bool {
                    $restaurant = $banner->restaurant;

                    $distance = $this->haversine->kilometresBetween(
                        $origin,
                        new GeoPoint((float) $restaurant->latitude, (float) $restaurant->longitude),
                    );

                    // Stashed on the model so BannerResource can expose it —
                    // the same pattern NearbyRestaurantFinder uses.
                    $banner->setAttribute('distance_km', $distance);

                    return $distance <= (float) $restaurant->delivery_radius_km;
                });

            return $platform->concat($restaurantBanners)
                ->sortBy('sort_order')
                ->values();
        });
    }
}
