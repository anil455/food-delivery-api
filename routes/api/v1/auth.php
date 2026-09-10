<?php

use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->as('auth.')->group(function (): void {
    // Coarse per-IP throttles sit in front of the precise per-phone quotas in
    // OtpRateLimiter, so a flood is rejected before it reaches the database.
    Route::middleware('throttle:otp-send')->group(function (): void {
        Route::post('otp/send', [OtpController::class, 'send'])->name('otp.send');
        Route::post('otp/resend', [OtpController::class, 'resend'])->name('otp.resend');
    });

    Route::post('otp/verify', [OtpController::class, 'verify'])
        ->middleware('throttle:otp-verify')
        ->name('otp.verify');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [SessionController::class, 'me'])->name('me');
        Route::patch('me', [SessionController::class, 'update'])->name('me.update');
        Route::post('logout', [SessionController::class, 'logout'])->name('logout');
        Route::post('logout-all', [SessionController::class, 'logoutAll'])->name('logout.all');
        Route::delete('account', [SessionController::class, 'destroyAccount'])->name('account.destroy');
    });
});
