<?php

declare(strict_types=1);

namespace App\Services\Geo;

use App\Models\Address;

/**
 * The address Nominatim resolved for a point, trimmed to what the app displays.
 *
 * Field names deliberately mirror {@see Address} (city, state,
 * postal_code, country) so a client can drop this straight into an address
 * form instead of remapping Nominatim's own vocabulary.
 */
final readonly class ReverseGeocodeResult
{
    public function __construct(
        public string $displayName,
        public ?string $area,
        public ?string $city,
        public ?string $state,
        public ?string $postalCode,
        public ?string $country,
    ) {}

    public static function fromNominatim(array $payload): self
    {
        $address = $payload['address'] ?? [];

        return new self(
            displayName: (string) ($payload['display_name'] ?? ''),
            // Nominatim has no single "neighbourhood" field; this is the order
            // that most reliably lands on something Zomato-style UIs show as
            // the bold first line (e.g. "Durga Colony").
            area: $address['suburb']
                ?? $address['neighbourhood']
                ?? $address['residential']
                ?? $address['road']
                ?? null,
            city: $address['city']
                ?? $address['town']
                ?? $address['village']
                ?? $address['county']
                ?? null,
            state: $address['state'] ?? null,
            postalCode: $address['postcode'] ?? null,
            country: $address['country'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'display_name' => $this->displayName,
            'area' => $this->area,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
        ];
    }
}
