<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StaffRole;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * A customer sees their own orders; staff see orders belonging to a
     * restaurant they work at. Nobody sees anything else.
     */
    public function view(User $user, Order $order): bool
    {
        if ((int) $order->user_id === (int) $user->getKey()) {
            return true;
        }

        return $user->hasRoleInRestaurant($order->restaurant_id);
    }

    /** Moving an order through the kitchen is ordinary staff work. */
    public function updateStatus(User $user, Order $order): bool
    {
        return $user->hasRoleInRestaurant($order->restaurant_id, StaffRole::Staff);
    }

    public function cancel(User $user, Order $order): bool
    {
        if ((int) $order->user_id === (int) $user->getKey()) {
            return $order->status->isCancellableByCustomer();
        }

        return $user->hasRoleInRestaurant($order->restaurant_id, StaffRole::Manager);
    }
}
