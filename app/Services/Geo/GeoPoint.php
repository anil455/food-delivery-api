<?php

declare(strict_types=1);

namespace App\Services\Geo;

use InvalidArgumentException;

/**
 * A validated latitude/longitude pair.
 *
 * Coordinates travel through the application as this object rather than as two
 * loose floats, which is what makes it impossible to transpose them by accident.
 */
final readonly class GeoPoint
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException("Latitude {$latitude} is outside the range -90 to 90.");
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException("Longitude {$longitude} is outside the range -180 to 180.");
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (float) ($data['latitude'] ?? $data['lat']),
            (float) ($data['longitude'] ?? $data['lng'] ?? $data['lon']),
        );
    }

    public function toArray(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }
}
