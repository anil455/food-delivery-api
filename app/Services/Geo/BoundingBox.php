<?php

declare(strict_types=1);

namespace App\Services\Geo;

/**
 * A latitude/longitude rectangle that fully contains a circle of a given radius.
 *
 * Stage one of the nearby search. It is a deliberate over-approximation: the box
 * corners lie outside the circle, so a handful of too-far restaurants are read
 * and then discarded by the exact distance in stage two. That trade buys a plain
 * B-tree index range scan instead of trigonometry across the whole table.
 */
final readonly class BoundingBox
{
    public function __construct(
        public float $minLatitude,
        public float $maxLatitude,
        public float $minLongitude,
        public float $maxLongitude,
    ) {}

    public static function around(GeoPoint $centre, float $radiusKm): self
    {
        $kmPerDegree = (float) config('geo.km_per_degree_latitude');
        $maxLatitude = (float) config('geo.max_absolute_latitude');

        $deltaLatitude = $radiusKm / $kmPerDegree;

        // A degree of longitude shrinks toward the poles, so the box has to grow
        // to compensate. Clamping the latitude keeps cos() away from zero, where
        // the delta would otherwise blow up to infinity.
        $clampedLatitude = max(-$maxLatitude, min($maxLatitude, $centre->latitude));
        $deltaLongitude = $radiusKm / ($kmPerDegree * cos(deg2rad($clampedLatitude)));

        return new self(
            minLatitude: max(-90.0, $centre->latitude - $deltaLatitude),
            maxLatitude: min(90.0, $centre->latitude + $deltaLatitude),
            minLongitude: max(-180.0, $centre->longitude - $deltaLongitude),
            maxLongitude: min(180.0, $centre->longitude + $deltaLongitude),
        );
    }

    public function contains(GeoPoint $point): bool
    {
        return $point->latitude >= $this->minLatitude
            && $point->latitude <= $this->maxLatitude
            && $point->longitude >= $this->minLongitude
            && $point->longitude <= $this->maxLongitude;
    }
}
