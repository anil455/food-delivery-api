<?php

declare(strict_types=1);

namespace App\Enums;

enum UserType: string
{
    case Customer = 'customer';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Staff => 'Staff',
        };
    }

    /**
     * The Sanctum ability granted to a freshly issued token for this user type.
     * Super admins are upgraded to 'admin' at issue time.
     */
    public function defaultTokenAbility(): string
    {
        return match ($this) {
            self::Customer => 'customer',
            self::Staff => 'restaurant',
        };
    }
}
