<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StaffRole;
use App\Models\User;

/**
 * Shared policy for every tenant-owned catalogue model: categories, products,
 * variants, add-on groups, add-ons and coupons.
 *
 * They all answer the same two questions, so one policy is easier to audit than
 * six identical ones. Registered against each model in AuthServiceProvider.
 */
class CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function view(User $user, mixed $model): bool
    {
        return $user->hasRoleInRestaurant($model->restaurant_id);
    }

    public function create(User $user): bool
    {
        // The restaurant being created against comes from the tenant context,
        // which the middleware has already validated against membership.
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function update(User $user, mixed $model): bool
    {
        return $user->hasRoleInRestaurant($model->restaurant_id, StaffRole::Staff);
    }

    /** Removing menu items is a manager decision, not a shift decision. */
    public function delete(User $user, mixed $model): bool
    {
        return $user->hasRoleInRestaurant($model->restaurant_id, StaffRole::Manager);
    }
}
