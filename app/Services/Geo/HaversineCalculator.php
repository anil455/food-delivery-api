<?php

declare(strict_types=1);

namespace App\Services\Geo;

/**
 * Great-circle distance in kilometres.
 *
 * The PHP twin of the SQL expression in NearbyRestaurantFinder. Both exist so a
 * test can assert they agree: if the SQL is ever edited into something subtly
 * wrong, the cross-check fails rather than the API quietly returning plausible
 * but incorrect distances.
 */
final class HaversineCalculator
{
    public function kilometresBetween(GeoPoint $from, GeoPoint $to): float
    {
        $earthRadius = (float) config('geo.earth_radius_km');

        $cosine =
            cos(deg2rad($from->latitude)) * cos(deg2rad($to->latitude))
            * cos(deg2rad($to->longitude) - deg2rad($from->longitude))
            + sin(deg2rad($from->latitude)) * sin(deg2rad($to->latitude));

        // Floating-point error can push the argument fractionally above 1 for
        // near-identical coordinates, and acos() of that is NAN. Without this
        // clamp the closest restaurant silently drops out of the results.
        $cosine = min(1.0, max(-1.0, $cosine));

        return $earthRadius * acos($cosine);
    }
}
