<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The User model guards type, is_super_admin and status so a request can
     * never set them. Factories are trusted code, not request input, so they
     * bypass the guard here rather than weakening it on the model.
     */
    public function newModel(array $attributes = []): Model
    {
        return (new User)->forceFill($attributes);
    }

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => $this->uniqueIndianPhone(),
            'phone_verified_at' => now(),
            'email' => null,
            'password' => null,
            'email_verified_at' => null,
            'type' => UserType::Customer,
            'is_super_admin' => false,
            'status' => 'active',
            'selected_restaurant_id' => null,
            'avatar_path' => null,
            'last_login_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /** A restaurant staff member: email + password, no phone login. */
    public function staff(): static
    {
        return $this->state(fn (array $attributes): array => [
            'phone' => null,
            'phone_verified_at' => null,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'type' => UserType::Staff,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->staff()->state(fn (array $attributes): array => [
            'is_super_admin' => true,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'blocked',
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'phone_verified_at' => null,
            'email_verified_at' => null,
        ]);
    }

    /**
     * E.164 Indian mobile numbers. Generated rather than faked so they always
     * survive libphonenumber validation in Phase 2.
     */
    private function uniqueIndianPhone(): string
    {
        return '+91'.fake()->unique()->numerify('9#########');
    }
}
