<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Addon;
use App\Models\AddonGroup;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Restaurant;
use App\Models\User;
use App\Policies\BannerPolicy;
use App\Policies\CatalogPolicy;
use App\Policies\OrderPolicy;
use App\Policies\RestaurantPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Every tenant-owned catalogue model shares one policy, because they all
     * answer the same question: does this user hold a sufficient role at the
     * restaurant that owns the record?
     */
    private const POLICIES = [
        Restaurant::class => RestaurantPolicy::class,
        Order::class => OrderPolicy::class,
        Banner::class => BannerPolicy::class,
        Category::class => CatalogPolicy::class,
        Product::class => CatalogPolicy::class,
        ProductVariant::class => CatalogPolicy::class,
        AddonGroup::class => CatalogPolicy::class,
        Addon::class => CatalogPolicy::class,
        Coupon::class => CatalogPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        /*
         * Super admins pass every gate. Returning null rather than false for
         * everyone else is essential: false here would deny every check outright
         * and the policies would never run.
         */
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->isSuperAdmin() ? true : null;
        });
    }
}
