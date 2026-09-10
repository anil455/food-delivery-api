<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Addon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Addon>
 */
class AddonFactory extends Factory
{
    protected $model = Addon::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Extra cheese', 'Masala chai', 'Coke', 'Raita', 'Extra gravy']),
            'price' => fake()->numberBetween(1500, 8000),   // 15.00 to 80.00
            'is_available' => true,
            'sort_order' => 0,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn (): array => ['is_available' => false]);
    }
}
