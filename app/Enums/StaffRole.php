<?php

declare(strict_types=1);

namespace App\Enums;

enum StaffRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::Staff => 'Staff',
        };
    }

    /**
     * Rank is used for "at least this role" checks in policies.
     * Higher wins.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 30,
            self::Manager => 20,
            self::Staff => 10,
        };
    }

    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    /** Roles permitted to manage other staff members. */
    public function canManageStaff(): bool
    {
        return $this->atLeast(self::Manager);
    }
}
