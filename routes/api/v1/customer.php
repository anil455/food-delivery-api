<?php

use App\Http\Controllers\Api\V1\Customer\AddressController;
use App\Http\Controllers\Api\V1\Customer\CartController;
use App\Http\Controllers\Api\V1\Customer\OrderController;
use App\Http\Controllers\Api\V1\Customer\MenuController;
use App\Http\Controllers\Api\V1\Customer\StoreContextController;
use Illuminate\Support\Facades\Route;

/*
| Authenticated customer routes. The customer token ability and the user type
| are both checked, so a staff token cannot reach these even if it is valid.
*/

Route::middleware(['auth:sanctum', 'ability:customer', 'user.type:customer', 'throttle:api'])
    ->group(function (): void {
        Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
        Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
        Route::get('addresses/{address}', [AddressController::class, 'show'])->name('addresses.show');
        Route::patch('addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
        Route::delete('addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');
        Route::post('addresses/{address}/default', [AddressController::class, 'makeDefault'])->name('addresses.default');

        Route::get('store-context', [StoreContextController::class, 'show'])->name('store-context.show');
        Route::post('store-context', [StoreContextController::class, 'store'])->name('store-context.store');
        Route::delete('store-context', [StoreContextController::class, 'destroy'])->name('store-context.destroy');
    });

/*
| Menu of the customer's currently selected store. The tenant middleware runs
| first, so every query inside is scoped without the controller saying so.
*/
Route::middleware(['auth:sanctum', 'ability:customer', 'user.type:customer', 'tenant.customer', 'throttle:api'])
    ->prefix('menu')->as('menu.')
    ->group(function (): void {
        Route::get('categories', [MenuController::class, 'categories'])->name('categories');
        Route::get('products', [MenuController::class, 'products'])->name('products');
        Route::get('products/{product}', [MenuController::class, 'show'])->name('products.show');
    });

/*
| Cart. Also behind the tenant middleware, because adding an item is always an
| action against the store the customer is currently browsing.
*/
Route::middleware(['auth:sanctum', 'ability:customer', 'user.type:customer', 'throttle:api'])
    ->group(function (): void {
        Route::get('cart', [CartController::class, 'show'])->name('cart.show');
        Route::get('cart/summary', [CartController::class, 'summary'])->name('cart.summary');
        Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');
        Route::patch('cart/items/{item}', [CartController::class, 'update'])->name('cart.items.update');
        Route::delete('cart/items/{item}', [CartController::class, 'destroyItem'])->name('cart.items.destroy');
        Route::post('cart/coupon', [CartController::class, 'applyCoupon'])->name('cart.coupon.apply');
        Route::delete('cart/coupon', [CartController::class, 'removeCoupon'])->name('cart.coupon.remove');
    });

// Adding an item needs the tenant context resolved, so it carries the extra
// middleware the read-only cart routes above do not.
Route::middleware(['auth:sanctum', 'ability:customer', 'user.type:customer', 'tenant.customer', 'throttle:api'])
    ->post('cart/items', [CartController::class, 'store'])
    ->name('cart.items.store');

/*
| Orders. Placement is throttled separately and harder than the rest: it is the
| one customer action that moves money.
*/
Route::middleware(['auth:sanctum', 'ability:customer', 'user.type:customer'])
    ->group(function (): void {
        Route::post('orders', [OrderController::class, 'store'])
            ->middleware('throttle:orders')
            ->name('orders.store');

        Route::middleware('throttle:api')->group(function (): void {
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
            Route::get('orders/{order}/track', [OrderController::class, 'track'])->name('orders.track');
            Route::post('orders/{order}/reorder', [OrderController::class, 'reorder'])->name('orders.reorder');
        });
    });
