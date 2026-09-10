<?php

declare(strict_types=1);

namespace App\Services\Otp;

/** Test transport. Discards the code so no test can accidentally depend on delivery. */
final class NullOtpChannel implements OtpChannel
{
    public function send(string $phone, string $code, string $purpose): void
    {
        // Intentionally empty.
    }
}
