<?php

use App\Http\Controllers\Api\V1\Public\BannerController;
use App\Http\Controllers\Api\V1\Public\ConfigController;
use App\Http\Controllers\Api\V1\Public\NearbyRestaurantController;
use App\Http\Controllers\Api\V1\Public\RestaurantController;
use App\Http\Controllers\Api\V1\Public\ReverseGeocodeController;
use Illuminate\Support\Facades\Route;

/*
| Public discovery and browsing. No authentication: these are the endpoints a
| customer uses to decide which restaurant they want, before signing in.
|
| Literal segments are registered before the {slug} wildcard so that
| /restaurants/nearby is never swallowed as a slug.
*/

Route::middleware('throttle:api')->group(function (): void {
    Route::get('config', ConfigController::class)->name('config');
    Route::get('banners', BannerController::class)->name('banners');

    Route::get('restaurants/nearby', NearbyRestaurantController::class)->name('restaurants.nearby');
    Route::get('restaurants', [RestaurantController::class, 'index'])->name('restaurants.index');
    Route::get('geocode/reverse', ReverseGeocodeController::class)->name('geocode.reverse');

    Route::get('restaurants/{slug}', [RestaurantController::class, 'show'])->name('restaurants.show');
    Route::get('restaurants/{slug}/hours', [RestaurantController::class, 'hours'])->name('restaurants.hours');
    Route::get('restaurants/{slug}/categories', [RestaurantController::class, 'categories'])->name('restaurants.categories');
    Route::get('restaurants/{slug}/products', [RestaurantController::class, 'products'])->name('restaurants.products');
    Route::get('restaurants/{slug}/products/{productSlug}', [RestaurantController::class, 'product'])->name('restaurants.product');
});
