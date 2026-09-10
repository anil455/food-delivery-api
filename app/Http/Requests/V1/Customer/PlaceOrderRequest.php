<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Constrained to the caller own addresses, so another customer
            // address id cannot become a delivery target.
            'address_id' => [
                'required', 'integer',
                Rule::exists('addresses', 'id')
                    ->where('user_id', $this->user()->getKey())
                    ->whereNull('deleted_at'),
            ],
            'payment_method' => ['required', 'string', Rule::in(['cod', 'online'])],
            'special_instructions' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Minor units, capped so a fat finger cannot tip a lakh of rupees.
            'tip' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'address_id.exists' => 'Select a delivery address saved to your account.',
        ];
    }
}
