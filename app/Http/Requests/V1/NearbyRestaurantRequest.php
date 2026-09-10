<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Services\Geo\GeoPoint;
use Illuminate\Foundation\Http\FormRequest;

class NearbyRestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Query strings carry booleans as words. "?only_deliverable=true" is what a
     * browser or fetch client sends, and rejecting it as invalid would be a
     * needlessly hostile API.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('only_deliverable')) {
            $this->merge([
                'only_deliverable' => filter_var(
                    $this->input('only_deliverable'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                ),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['sometimes', 'numeric', 'min:0.1', 'max:'.config('geo.max_search_radius_km')],
            'only_deliverable' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.config('geo.result_limit')],
        ];
    }

    public function origin(): GeoPoint
    {
        return new GeoPoint(
            (float) $this->validated('latitude'),
            (float) $this->validated('longitude'),
        );
    }

    public function radiusKm(): ?float
    {
        $radius = $this->validated('radius');

        return $radius === null ? null : (float) $radius;
    }

    public function onlyDeliverable(): bool
    {
        return $this->boolean('only_deliverable');
    }
}
