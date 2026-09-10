<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->randomElement([
            'Paneer Tikka', 'Butter Chicken', 'Dal Makhani', 'Veg Biryani',
            'Garlic Naan', 'Gulab Jamun', 'Masala Chai', 'Chicken Momos',
        ]).' '.Str::upper(Str::random(3));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(12),
            'image_path' => null,
            // Minor units: between 79.00 and 549.00
            'base_price' => fake()->numberBetween(7900, 54900),
            'compare_at_price' => null,
            'tax_percentage' => null,
            'is_veg' => fake()->boolean(60),
            'spice_level' => fake()->numberBetween(0, 3),
            'prep_time_minutes' => fake()->numberBetween(5, 25),
            'is_available' => true,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 50),
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn (): array => ['is_available' => false]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function pricedAt(int $minorUnits): static
    {
        return $this->state(fn (): array => ['base_price' => $minorUnits]);
    }
}
