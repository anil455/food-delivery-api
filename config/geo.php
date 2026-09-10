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
