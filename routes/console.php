<?php

use Illuminate\Support\Facades\Schedule;

/*
| Housekeeping.
|
| Both of these exist because expired credentials that linger are a liability:
| a pruned table cannot leak anything.
*/

// Sanctum tokens past their expiry are dead weight and an audit risk.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Consumed and expired OTP rows hold a bcrypt hash of a live-ish code and the
// requesting IP. Nothing needs them after a day.
Schedule::call(function (): void {
    \App\Models\OtpVerification::query()
        ->where('expires_at', '<', now()->subDay())
        ->delete();
})->hourly()->name('prune-otp-verifications')->withoutOverlapping();
