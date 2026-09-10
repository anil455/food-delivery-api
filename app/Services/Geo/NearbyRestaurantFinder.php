<?php

declare(strict_types=1);

namespace App\Services\Geo;

use App\Enums\RestaurantStatus;
use App\Models\Restaurant;
use App\Services\Tenancy\RestaurantContext;
use Illuminate\Support\Collection;

/**
 * The nearby-restaurant search.
 *
 * Two stages, both in the database:
 *   1. an inner query does a bounding-box range scan on the composite index
 *      (status, latitude, longitude) and computes exact Haversine distance
 *   2. an outer query filters and orders on that distance
 *
 * Distance is never computed in PHP across the table. What PHP does compute, on
 * the small result set only, is whether each restaurant is currently open, which
 * needs per-restaurant timezones and multi-slot schedules and would be
 * unreadable in SQL.
 */
final class NearbyRestaurantFinder
{
    public function __construct(
        private readonly RestaurantContext $context,
    ) {}

    /**
     * @return Collection<int, Restaurant> each carrying a `distance_km` attribute
     */
    public function find(
        GeoPoint $origin,
        ?float $radiusKm = null,
        bool $onlyDeliverable = false,
        ?int $limit = null,
        ?string $search = null,
    ): Collection {
        $radiusKm = $this->clampRadius($radiusKm);
        $limit ??= (int) config('geo.result_limit');
        $box = BoundingBox::around($origin, $radiusKm);

        // Searching for restaurants is cross-tenant by definition: it is the
        // query that decides which tenant the customer will use next.
        return $this->context->runCrossTenant(function () use ($origin, $radiusKm, $box, $onlyDeliverable, $limit, $search) {
            // Stage 1. The two BETWEEN clauses are what let this use the
            // composite index instead of running trigonometry over every row.
            $inner = Restaurant::query()
                ->select('restaurants.*')
                ->selectRaw($this->distanceExpression().' as distance_km', [
                    $origin->latitude,
                    $origin->longitude,
                    $origin->latitude,
                ])
                ->where('status', RestaurantStatus::Active->value)
                ->whereBetween('latitude', [$box->minLatitude, $box->maxLatitude])
                ->whereBetween('longitude', [$box->minLongitude, $box->maxLongitude])
                ->when(
                    $search !== null && $search !== '',
                    fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                );

            /*
             * Stage 2 runs against a derived table rather than a HAVING clause.
             * MariaDB rejects a plain column in HAVING without a GROUP BY, where
             * MySQL permits it; wrapping the distance in a subquery makes the
             * alias available to WHERE and behaves identically on both engines.
             */
            $query = Restaurant::query()
                ->fromSub($inner, 'restaurants')
                ->where('distance_km', '<=', $radiusKm);

            if ($onlyDeliverable) {
                $query->whereColumn('distance_km', '<=', 'delivery_radius_km');
            }

            return $query
                ->orderBy('distance_km')
                ->limit($limit)
                ->with('hours')
                ->get();
        });
    }

    /**
     * Distance from a restaurant row to the origin, in kilometres.
     *
     * Placeholders bind latitude, longitude, latitude in that order. Columns are
     * named explicitly rather than positioned, which is precisely why this form
     * cannot suffer the latitude/longitude axis-order trap that SRID-aware
     * spatial functions invite.
     *
     * LEAST(1.0, ...) is not decoration: floating-point error can push the
     * cosine fractionally above 1 for near-identical coordinates, ACOS of that
     * is NULL, and the closest restaurant would silently drop out.
     */
    private function distanceExpression(): string
    {
        $earthRadius = (float) config('geo.earth_radius_km');

        return "({$earthRadius} * ACOS(LEAST(1.0,"
            .' COS(RADIANS(?)) * COS(RADIANS(restaurants.latitude))'
            .' * COS(RADIANS(restaurants.longitude) - RADIANS(?))'
            .' + SIN(RADIANS(?)) * SIN(RADIANS(restaurants.latitude))'
            .')))';
    }

    private function clampRadius(?float $radiusKm): float
    {
        $default = (float) config('geo.default_search_radius_km');
        $max = (float) config('geo.max_search_radius_km');

        if ($radiusKm === null || $radiusKm <= 0.0) {
            return $default;
        }

        return min($radiusKm, $max);
    }
}
