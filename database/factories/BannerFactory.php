<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Banner>
 */
class BannerFactory extends Factory
{
    protected $model = Banner::class;

    /** restaurant_id is guarded to null by default; factories set it directly. */
    public function newModel(array $attributes = []): Model
    {
        return (new Banner)->forceFill($attributes);
    }

    public function definition(): array
    {
        return [
            'restaurant_id' => null,
            'title' => fake()->sentence(3),
            'subtitle' => fake()->sentence(6),
            'image_path' => null,
            'link_type' => null,
            'link_value' => null,
            'starts_at' => null,
            'ends_at' => null,
            'sort_order' => fake()->numberBetween(0, 50),
            'is_active' => true,
        ];
    }

    public function forRestaurant(int $restaurantId): static
    {
        return $this->state(fn (array $attributes): array => [
            'restaurant_id' => $restaurantId,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ends_at' => now()->subDay(),
        ]);
    }

    public function notYetStarted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => now()->addDay(),
        ]);
    }
}
