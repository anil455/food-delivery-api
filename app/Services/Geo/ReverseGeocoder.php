<?php

declare(strict_types=1);

namespace App\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns a coordinate into the address a customer would recognise, via
 * LocationIQ's reverse-geocoding API (OSM data, same response shape as
 * Nominatim, served from infrastructure that accepts cloud/datacenter
 * traffic — Nominatim's own public server does not, see
 * https://operations.osmfoundation.org/policies/nominatim/).
 *
 * Results are cached per coordinate, rounded to ~11 metres: it's the free
 * tier, and a customer's device re-sends the same GPS fix on every app open
 * and screen focus.
 */
final class ReverseGeocoder
{
    public function locate(GeoPoint $point): ?ReverseGeocodeResult
    {
        $ttlMinutes = (int) config('geo.reverse_geocode_cache_minutes');

        return Cache::remember(
            $this->cacheKey($point),
            now()->addMinutes($ttlMinutes),
            fn () => $this->fetch($point),
        );
    }

    private function fetch(GeoPoint $point): ?ReverseGeocodeResult
    {
        $baseUrl = rtrim((string) config('geo.locationiq_base_url'), '/');

        try {
            $response = Http::timeout(5)
                ->get("{$baseUrl}/reverse", [
                    'key' => (string) config('geo.locationiq_api_key'),
                    'lat' => $point->latitude,
                    'lon' => $point->longitude,
                    'format' => 'json',
                    'zoom' => 18,
                    'addressdetails' => 1,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('LocationIQ reverse geocode: connection failed', [
                'point' => $point->toArray(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $payload = $response->json();

        // A 2xx status doesn't guarantee a decodable JSON object: a rate-limit
        // or maintenance response can still arrive as plain text with a 200,
        // and json() then returns null rather than throwing.
        if ($response->failed() || ! is_array($payload) || ($payload['error'] ?? null) !== null) {
            Log::warning('LocationIQ reverse geocode: request rejected', [
                'point' => $point->toArray(),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return ReverseGeocodeResult::fromAddressLookup($payload);
    }

    private function cacheKey(GeoPoint $point): string
    {
        return sprintf('geocode:reverse:%.4f:%.4f', $point->latitude, $point->longitude);
    }
}
