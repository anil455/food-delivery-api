<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Regular', 'Large', 'Family', 'Half', 'Full']),
            'sku' => null,
            // Absolute price, not a delta: a variant replaces base_price outright.
            'price' => fake()->numberBetween(9900, 69900),
            'is_default' => false,
            'is_available' => true,
            'sort_order' => 0,
        ];
    }

    public function pricedAt(int $minorUnits): static
    {
        return $this->state(fn (): array => ['price' => $minorUnits]);
    }
}
