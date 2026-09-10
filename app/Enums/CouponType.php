<?php

declare(strict_types=1);

namespace App\Enums;

enum CouponType: string
{
    case Fixed = 'fixed';
    case Percent = 'percent';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount',
            self::Percent => 'Percentage',
        };
    }
}
