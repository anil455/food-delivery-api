<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Services\Geo\GeoPoint;
use Illuminate\Foundation\Http\FormRequest;

class BannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Unlike nearby-restaurant search, coordinates are optional here: a caller
     * with no known location still gets the platform banners, just none of
     * the restaurant-specific ones.
     */
    public function rules(): array
    {
        return [
            'latitude' => ['sometimes', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }

    public function origin(): ?GeoPoint
    {
        $latitude = $this->validated('latitude');
        $longitude = $this->validated('longitude');

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return new GeoPoint((float) $latitude, (float) $longitude);
    }
}
