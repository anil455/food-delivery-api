<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Services\Geo\GeoPoint;
use Illuminate\Foundation\Http\FormRequest;

class ReverseGeocodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function origin(): GeoPoint
    {
        return new GeoPoint(
            (float) $this->validated('latitude'),
            (float) $this->validated('longitude'),
        );
    }
}
