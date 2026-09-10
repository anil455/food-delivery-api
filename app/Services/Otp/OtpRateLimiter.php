<?php

declare(strict_types=1);

namespace App\Services\Otp;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\OtpException;
use App\Exceptions\Api\RateLimitedException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Every quota that guards the OTP endpoints.
 *
 * Kept apart from OtpService so the limits can be read and tested as one unit.
 * Phone quotas stop a single number being spammed; IP quotas stop one client
 * enumerating many numbers.
 */
final class OtpRateLimiter
{
    /** @throws ApiException */
    public function assertCanSend(string $phone, ?string $ip): void
    {
        // Tapping resend early is normal user behaviour, so the cooldown gets
        // its own error code rather than a generic 429.
        $cooldownKey = $this->cooldownKey($phone);

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            throw OtpException::resendCooldown(RateLimiter::availableIn($cooldownKey));
        }

        $quotas = [
            [$this->phoneKey($phone, 'hour'), (int) config('otp.per_phone_hourly')],
            [$this->phoneKey($phone, 'day'), (int) config('otp.per_phone_daily')],
        ];

        if ($ip !== null) {
            $quotas[] = [$this->ipKey($ip, 'hour'), (int) config('otp.per_ip_hourly')];
            $quotas[] = [$this->ipKey($ip, 'day'), (int) config('otp.per_ip_daily')];
        }

        foreach ($quotas as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new RateLimitedException(
                    'Too many verification codes requested. Please try again later.',
                    RateLimiter::availableIn($key),
                );
            }
        }
    }

    public function recordSend(string $phone, ?string $ip): void
    {
        RateLimiter::hit($this->cooldownKey($phone), (int) config('otp.resend_cooldown_seconds'));
        RateLimiter::hit($this->phoneKey($phone, 'hour'), 3600);
        RateLimiter::hit($this->phoneKey($phone, 'day'), 86400);

        if ($ip !== null) {
            RateLimiter::hit($this->ipKey($ip, 'hour'), 3600);
            RateLimiter::hit($this->ipKey($ip, 'day'), 86400);
        }
    }

    /**
     * The brute-force floor. Attempts per code are capped in OtpService; this
     * caps how fast one client can burn through codes at all.
     *
     * @throws ApiException
     */
    public function assertCanVerify(?string $ip): void
    {
        if ($ip === null) {
            return;
        }

        $key = $this->verifyKey($ip);

        if (RateLimiter::tooManyAttempts($key, (int) config('otp.verify_per_ip_per_10min'))) {
            throw new RateLimitedException(
                'Too many verification attempts. Please try again later.',
                RateLimiter::availableIn($key),
            );
        }
    }

    public function recordVerify(?string $ip): void
    {
        if ($ip !== null) {
            RateLimiter::hit($this->verifyKey($ip), 600);
        }
    }

    /** Lift a lockout deliberately, for tests and support tooling. */
    public function clear(string $phone, ?string $ip = null): void
    {
        RateLimiter::clear($this->cooldownKey($phone));
        RateLimiter::clear($this->phoneKey($phone, 'hour'));
        RateLimiter::clear($this->phoneKey($phone, 'day'));

        if ($ip !== null) {
            RateLimiter::clear($this->ipKey($ip, 'hour'));
            RateLimiter::clear($this->ipKey($ip, 'day'));
            RateLimiter::clear($this->verifyKey($ip));
        }
    }

    // Phone numbers and IPs are hashed into keys so they never sit in the cache
    // store as readable identifiers.
    private function cooldownKey(string $phone): string
    {
        return 'otp:cooldown:'.sha1($phone);
    }

    private function phoneKey(string $phone, string $window): string
    {
        return "otp:phone:{$window}:".sha1($phone);
    }

    private function ipKey(string $ip, string $window): string
    {
        return "otp:ip:{$window}:".sha1($ip);
    }

    private function verifyKey(string $ip): string
    {
        return 'otp:verify:'.sha1($ip);
    }
}
