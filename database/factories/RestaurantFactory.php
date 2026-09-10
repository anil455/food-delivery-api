<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RestaurantStatus;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    /** Status is guarded on the model; factories set it directly. */
    public function newModel(array $attributes = []): Model
    {
        return (new Restaurant)->forceFill($attributes);
    }

    public function definition(): array
    {
        $name = fake()->company().' Kitchen';

        // Scattered across Delhi NCR so distance assertions have real spread.
        $latitude = fake()->randomFloat(6, 28.40, 28.75);
        $longitude = fake()->randomFloat(6, 76.95, 77.40);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => fake()->sentence(12),
            'phone' => '+91'.fake()->numerify('9#########'),
            'email' => fake()->unique()->companyEmail(),

            'address_line' => fake()->streetAddress(),
            'city' => 'New Delhi',
            'state' => 'Delhi',
            'postal_code' => fake()->numerify('1100##'),
            'country' => 'IN',

            'latitude' => $latitude,
            'longitude' => $longitude,
            'delivery_radius_km' => fake()->randomElement([3, 5, 7, 10]),

            // Minor units: 15000 paise = 150.00
            'min_order_amount' => 15000,
            'delivery_fee_base' => 3000,
            'delivery_fee_per_km' => 1000,
            'packaging_fee' => 2000,
            'tax_percentage' => 5.00,
            'commission_rate' => 18.00,

            'avg_prep_time_minutes' => fake()->numberBetween(15, 40),
            'timezone' => 'Asia/Kolkata',

            'status' => RestaurantStatus::Active,
            'is_accepting_orders' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RestaurantStatus::Inactive,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RestaurantStatus::Suspended,
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RestaurantStatus::PendingApproval,
        ]);
    }

    public function notAcceptingOrders(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_accepting_orders' => false,
        ]);
    }

    /** Pin a restaurant to exact coordinates, for distance assertions. */
    public function at(float $latitude, float $longitude): static
    {
        return $this->state(fn (array $attributes): array => [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }
}
