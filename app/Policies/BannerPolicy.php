<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StaffRole;
use App\Models\Banner;
use App\Models\User;

/**
 * Shaped like CatalogPolicy, but cannot reuse it: restaurant_id is nullable
 * here (a platform banner has none), and CatalogPolicy passes it straight
 * into hasRoleInRestaurant(), which only accepts Restaurant|int and throws
 * on null. A staff member never manages a platform banner anyway — the
 * super admin who does gets through via Gate::before, before any of this
 * runs — so a null restaurant_id here just means "not theirs."
 */
class BannerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function view(User $user, Banner $banner): bool
    {
        return $banner->restaurant_id !== null
            && $user->hasRoleInRestaurant($banner->restaurant_id);
    }

    public function create(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function update(User $user, Banner $banner): bool
    {
        return $banner->restaurant_id !== null
            && $user->hasRoleInRestaurant($banner->restaurant_id, StaffRole::Staff);
    }

    public function delete(User $user, Banner $banner): bool
    {
        return $banner->restaurant_id !== null
            && $user->hasRoleInRestaurant($banner->restaurant_id, StaffRole::Manager);
    }
}
