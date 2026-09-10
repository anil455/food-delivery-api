<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StaffRole;
use App\Models\Restaurant;
use App\Models\User;

/**
 * Layer 4 for the tenant root itself.
 *
 * Gate::before in AuthServiceProvider short-circuits all of these for super
 * admins, so every method here answers only the question: does this staff
 * member hold the right role at this specific restaurant?
 */
class RestaurantPolicy
{
    public function view(User $user, Restaurant $restaurant): bool
    {
        return $user->hasRoleInRestaurant($restaurant);
    }

    public function update(User $user, Restaurant $restaurant): bool
    {
        return $user->hasRoleInRestaurant($restaurant, StaffRole::Manager)
            && $restaurant->status->allowsStaffWrites();
    }

    /** Pausing and resuming orders is a day-to-day action, so staff may do it. */
    public function toggleOrders(User $user, Restaurant $restaurant): bool
    {
        return $user->hasRoleInRestaurant($restaurant, StaffRole::Staff)
            && $restaurant->status->allowsStaffWrites();
    }

    public function manageStaff(User $user, Restaurant $restaurant): bool
    {
        return $user->hasRoleInRestaurant($restaurant, StaffRole::Manager)
            && $restaurant->status->allowsStaffWrites();
    }

    /** Only the platform creates, deletes or suspends restaurants. */
    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, Restaurant $restaurant): bool
    {
        return false;
    }
}
