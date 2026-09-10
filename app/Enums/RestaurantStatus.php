<?php

declare(strict_types=1);

namespace App\Enums;

enum RestaurantStatus: string
{
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Pending approval',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Suspended => 'Suspended',
        };
    }

    /** Only active restaurants are discoverable by customers. */
    public function isDiscoverable(): bool
    {
        return $this === self::Active;
    }

    /** Suspended restaurants keep their data but lose staff write access. */
    public function allowsStaffWrites(): bool
    {
        return $this !== self::Suspended;
    }
}
