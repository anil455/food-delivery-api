<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case ReadyForPickup = 'ready_for_pickup';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Preparing => 'Preparing',
            self::ReadyForPickup => 'Ready for pickup',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * The complete transition table. Anything not listed here is forbidden —
     * this is the single source of truth for order lifecycle rules.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Rejected, self::Cancelled],
            self::Confirmed => [self::Preparing, self::Cancelled],
            self::Preparing => [self::ReadyForPickup, self::Cancelled],
            self::ReadyForPickup => [self::OutForDelivery],
            self::OutForDelivery => [self::Delivered],
            self::Delivered, self::Cancelled, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Terminal states can never transition again. */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** A customer may cancel only before the kitchen commits to the order. */
    public function isCancellableByCustomer(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed], true);
    }

    /** Statuses that count as live orders on the restaurant dashboard. */
    public static function active(): array
    {
        return [
            self::Pending,
            self::Confirmed,
            self::Preparing,
            self::ReadyForPickup,
            self::OutForDelivery,
        ];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
