<?php

use App\Http\Controllers\Api\V1\Admin\AddonController;
use App\Http\Controllers\Api\V1\Admin\AddonGroupController;
use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\BannerController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\HolidayController;
use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\RestaurantController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Controllers\Api\V1\Admin\TwoFactorController;
use App\Http\Controllers\Api\V1\SuperAdmin\BannerController as PlatformBannerController;
use App\Http\Controllers\Api\V1\SuperAdmin\PlatformController;
use App\Http\Controllers\Api\V1\SuperAdmin\RestaurantController as PlatformRestaurantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Restaurant admin
|--------------------------------------------------------------------------
| Four gates stack before any of these run:
|   auth:sanctum          the token is real
|   ability:restaurant    the token is a staff token, not a customer one
|   user.type:staff       the account is a staff account
|   tenant.staff          X-Restaurant-Id was validated against membership
|
| Policies then check the role. None of these routes take a restaurant id,
| because the tenant is resolved from a proven membership rather than input.
*/

Route::prefix('admin')->as('admin.')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'ability:restaurant,admin', 'user.type:staff', 'throttle:api'])
        ->group(function (): void {
            Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
            Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

            // Two-factor management for the signed-in account. Every
            // state-changing call re-checks the password, so a stolen token
            // alone cannot turn the second factor off.
            Route::get('auth/2fa', [TwoFactorController::class, 'status'])->name('auth.2fa.status');
            Route::post('auth/2fa/setup', [TwoFactorController::class, 'setup'])->name('auth.2fa.setup');
            Route::post('auth/2fa/confirm', [TwoFactorController::class, 'confirm'])->name('auth.2fa.confirm');
            Route::post('auth/2fa/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('auth.2fa.recovery');
            Route::delete('auth/2fa', [TwoFactorController::class, 'disable'])->name('auth.2fa.disable');
        });

    Route::middleware([
        'auth:sanctum', 'ability:restaurant,admin', 'user.type:staff', 'tenant.staff', 'throttle:api',
    ])->group(function (): void {
        Route::get('restaurant', [RestaurantController::class, 'show'])->name('restaurant.show');
        Route::patch('restaurant', [RestaurantController::class, 'update'])->name('restaurant.update');
        Route::patch('restaurant/status', [RestaurantController::class, 'toggleOrders'])->name('restaurant.status');
        Route::get('restaurant/hours', [RestaurantController::class, 'hours'])->name('restaurant.hours');
        Route::put('restaurant/hours', [RestaurantController::class, 'updateHours'])->name('restaurant.hours.update');

        Route::get('banners', [BannerController::class, 'index'])->name('banners.index');
        Route::post('banners', [BannerController::class, 'store'])->name('banners.store');
        Route::get('banners/{banner}', [BannerController::class, 'show'])->name('banners.show');
        Route::patch('banners/{banner}', [BannerController::class, 'update'])->name('banners.update');
        Route::delete('banners/{banner}', [BannerController::class, 'destroy'])->name('banners.destroy');
        Route::post('banners/{banner}/image', [BannerController::class, 'uploadImage'])->name('banners.image');

        Route::post('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::get('categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
        Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::post('categories/{category}/image', [CategoryController::class, 'uploadImage'])->name('categories.image');

        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::patch('products/{product}/availability', [ProductController::class, 'availability'])->name('products.availability');
        Route::post('products/{product}/image', [ProductController::class, 'uploadImage'])->name('products.image');

        Route::get('holidays', [HolidayController::class, 'index'])->name('holidays.index');
        Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::patch('holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');

        Route::get('products/{product}/variants', [ProductVariantController::class, 'index'])->name('variants.index');
        Route::post('products/{product}/variants', [ProductVariantController::class, 'store'])->name('variants.store');
        Route::patch('products/{product}/variants/{variant}', [ProductVariantController::class, 'update'])->name('variants.update');
        Route::delete('products/{product}/variants/{variant}', [ProductVariantController::class, 'destroy'])->name('variants.destroy');

        Route::get('addon-groups', [AddonGroupController::class, 'index'])->name('addon-groups.index');
        Route::post('addon-groups', [AddonGroupController::class, 'store'])->name('addon-groups.store');
        Route::get('addon-groups/{addonGroup}', [AddonGroupController::class, 'show'])->name('addon-groups.show');
        Route::patch('addon-groups/{addonGroup}', [AddonGroupController::class, 'update'])->name('addon-groups.update');
        Route::delete('addon-groups/{addonGroup}', [AddonGroupController::class, 'destroy'])->name('addon-groups.destroy');
        Route::put('addon-groups/{addonGroup}/products', [AddonGroupController::class, 'syncProducts'])->name('addon-groups.products');

        Route::get('addons', [AddonController::class, 'index'])->name('addons.index');
        Route::post('addons', [AddonController::class, 'store'])->name('addons.store');
        Route::patch('addons/{addon}', [AddonController::class, 'update'])->name('addons.update');
        Route::patch('addons/{addon}/availability', [AddonController::class, 'availability'])->name('addons.availability');
        Route::delete('addons/{addon}', [AddonController::class, 'destroy'])->name('addons.destroy');

        Route::get('coupons', [CouponController::class, 'index'])->name('coupons.index');
        Route::post('coupons', [CouponController::class, 'store'])->name('coupons.store');
        Route::get('coupons/{coupon}', [CouponController::class, 'show'])->name('coupons.show');
        Route::patch('coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
        Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->name('coupons.destroy');

        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
        Route::get('dashboard/stats', [OrderController::class, 'stats'])->name('dashboard.stats');

        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
        Route::patch('staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
        Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Platform administration
|--------------------------------------------------------------------------
| Requires the admin token ability, which is only ever issued to an account
| with is_super_admin set. These routes are cross-tenant by design.
*/

Route::prefix('super-admin')->as('super-admin.')
    ->middleware(['auth:sanctum', 'ability:admin', 'user.type:staff', 'throttle:api'])
    ->group(function (): void {
        Route::get('restaurants', [PlatformRestaurantController::class, 'index'])->name('restaurants.index');
        Route::post('restaurants', [PlatformRestaurantController::class, 'store'])->name('restaurants.store');
        Route::get('restaurants/{restaurant}', [PlatformRestaurantController::class, 'show'])->name('restaurants.show');
        Route::patch('restaurants/{restaurant}', [PlatformRestaurantController::class, 'update'])->name('restaurants.update');
        Route::patch('restaurants/{restaurant}/status', [PlatformRestaurantController::class, 'updateStatus'])->name('restaurants.status');
        Route::delete('restaurants/{restaurant}', [PlatformRestaurantController::class, 'destroy'])->name('restaurants.destroy');

        Route::get('banners', [PlatformBannerController::class, 'index'])->name('banners.index');
        Route::post('banners', [PlatformBannerController::class, 'store'])->name('banners.store');
        Route::get('banners/{banner}', [PlatformBannerController::class, 'show'])->name('banners.show');
        Route::patch('banners/{banner}', [PlatformBannerController::class, 'update'])->name('banners.update');
        Route::delete('banners/{banner}', [PlatformBannerController::class, 'destroy'])->name('banners.destroy');
        Route::post('banners/{banner}/image', [PlatformBannerController::class, 'uploadImage'])->name('banners.image');

        Route::get('users', [PlatformController::class, 'users'])->name('users.index');
        Route::patch('users/{user}/status', [PlatformController::class, 'updateUserStatus'])->name('users.status');
        Route::get('orders', [PlatformController::class, 'orders'])->name('orders.index');
        Route::get('dashboard/stats', [PlatformController::class, 'stats'])->name('dashboard.stats');
    });
