<?php

declare(strict_types=1);

namespace App\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns a coordinate into the address a customer would recognise, via the
 * free Nominatim (OpenStreetMap) reverse-geocoding API.
 *
 * Results are cached per coordinate, rounded to ~11 metres: Nominatim's public
 * instance asks for no more than ~1 request/second, and a customer's device
 * re-sends the same GPS fix on every app open and screen focus.
 */
final class ReverseGeocoder
{
    public function locate(GeoPoint $point): ?ReverseGeocodeResult
    {
        $ttlMinutes = (int) config('geo.nominatim_cache_minutes');

        return Cache::remember(
            $this->cacheKey($point),
            now()->addMinutes($ttlMinutes),
            fn () => $this->fetch($point),
        );
    }

    private function fetch(GeoPoint $point): ?ReverseGeocodeResult
    {
        $baseUrl = rtrim((string) config('geo.nominatim_base_url'), '/');

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('geo.nominatim_user_agent'),
            ])
                ->timeout(5)
                ->get("{$baseUrl}/reverse", [
                    'format' => 'jsonv2',
                    'lat' => $point->latitude,
                    'lon' => $point->longitude,
                    'zoom' => 18,
                    'addressdetails' => 1,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Nominatim reverse geocode: connection failed', [
                'point' => $point->toArray(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed() || $response->json('error') !== null) {
            Log::warning('Nominatim reverse geocode: request rejected', [
                'point' => $point->toArray(),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return ReverseGeocodeResult::fromNominatim($response->json());
    }

    private function cacheKey(GeoPoint $point): string
    {
        return sprintf('geocode:reverse:%.4f:%.4f', $point->latitude, $point->longitude);
    }
}
