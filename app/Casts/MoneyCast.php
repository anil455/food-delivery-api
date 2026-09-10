<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts an integer minor-unit column to a Money object and back.
 *
 * Usage: protected $casts = ['base_price' => MoneyCast::class];
 */
final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::fromMinor((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            return $value->minor;
        }

        if (is_int($value)) {
            return $value;
        }

        throw new InvalidArgumentException(
            sprintf(
                'Attribute [%s] expects a Money instance or integer minor units, %s given. '
                .'Use Money::fromMajor() to convert a decimal amount.',
                $key,
                get_debug_type($value),
            )
        );
    }
}
