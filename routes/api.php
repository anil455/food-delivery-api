<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Split by audience rather than by resource, because the middleware stack is
| what differs between them: public routes have none, customer routes resolve a
| store, staff routes resolve a tenant from a validated membership.
*/

Route::prefix('v1')->as('v1.')->group(function (): void {
    require __DIR__.'/api/v1/auth.php';
    require __DIR__.'/api/v1/public.php';
    require __DIR__.'/api/v1/customer.php';
    require __DIR__.'/api/v1/admin.php';
});
