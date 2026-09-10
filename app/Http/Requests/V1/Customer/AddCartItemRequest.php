<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Customer;

use App\Services\Tenancy\RestaurantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Layer 5 of the isolation strategy in practice.
 *
 * The exists rules below are constrained to the current restaurant, which is
 * what closes the classic IDOR: a well-formed request from a legitimately
 * authenticated customer, carrying a real product id that belongs to a
 * different store.
 */
class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $restaurantId = app(RestaurantContext::class)->requireId();

        return [
            'product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')
                    ->where('restaurant_id', $restaurantId)
                    ->whereNull('deleted_at'),
            ],
            'product_variant_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('product_variants', 'id')->where('restaurant_id', $restaurantId),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],

            'addons' => ['sometimes', 'array', 'max:20'],
            'addons.*.addon_id' => [
                'required', 'integer',
                Rule::exists('addons', 'id')
                    ->where('restaurant_id', $restaurantId)
                    ->whereNull('deleted_at'),
            ],
            'addons.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],

            'special_instructions' => ['sometimes', 'nullable', 'string', 'max:255'],

            // The customer explicitly agreeing to discard a cart from another
            // restaurant. Never defaulted to true.
            'replace_cart' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.exists' => 'This item is not available at the selected restaurant.',
            'addons.*.addon_id.exists' => 'One of the selected options is not available here.',
        ];
    }
}
