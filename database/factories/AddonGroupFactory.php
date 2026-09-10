<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AddonGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddonGroup>
 */
class AddonGroupFactory extends Factory
{
    protected $model = AddonGroup::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Choice of drink', 'Extra toppings', 'Spice level', 'Add a side']),
            'description' => null,
            'min_select' => 0,
            'max_select' => 3,
            'is_required' => false,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function required(int $min = 1, int $max = 1): static
    {
        return $this->state(fn (): array => [
            'is_required' => true,
            'min_select' => $min,
            'max_select' => $max,
        ]);
    }
}
