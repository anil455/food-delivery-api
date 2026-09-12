<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Nearby search
    |--------------------------------------------------------------------------
    */
    'default_search_radius_km' => (float) env('GEO_DEFAULT_SEARCH_RADIUS_KM', 10),
    'max_search_radius_km' => (float) env('GEO_MAX_SEARCH_RADIUS_KM', 25),
    'result_limit' => (int) env('GEO_NEARBY_RESULT_LIMIT', 50),

    /*
    | The widest delivery_radius_km a restaurant is allowed to set (see the
    | Filament restaurant form). A restaurant that sets it still turns up for
    | a customer beyond the search radius above — see NearbyRestaurantFinder
    | — so this also sizes how wide that search has to look in the worst case.
    */
    'max_delivery_radius_km' => (float) env('GEO_MAX_DELIVERY_RADIUS_KM', 50),

    /*
    |--------------------------------------------------------------------------
    | Reverse geocoding (LocationIQ)
    |--------------------------------------------------------------------------
    | Nominatim's own public server rejects traffic from cloud/datacenter IPs
    | (Render included) with a 403 — see https://operations.osmfoundation.org/policies/nominatim/.
    | LocationIQ serves the same OSM data over the same response shape, but from
    | infrastructure meant for exactly this, and has a free tier that needs no
    | card. Results are still cached — see ReverseGeocoder.
    */
    'locationiq_base_url' => env('LOCATIONIQ_BASE_URL', 'https://us1.locationiq.com/v1'),
    'locationiq_api_key' => env('LOCATIONIQ_API_KEY'),
    'reverse_geocode_cache_minutes' => (int) env('REVERSE_GEOCODE_CACHE_MINUTES', 1440),

    /*
    |--------------------------------------------------------------------------
    | Constants
    |--------------------------------------------------------------------------
    | Mean earth radius in kilometres, and kilometres per degree of latitude —
    | the latter is used to size the bounding-box prefilter.
    */
    'earth_radius_km' => 6371.0,
    'km_per_degree_latitude' => 111.045,

    /*
    | cos(latitude) collapses toward the poles; clamping keeps the longitude
    | delta finite. Irrelevant for India, but free insurance.
    */
    'max_absolute_latitude' => 89.9,
];
