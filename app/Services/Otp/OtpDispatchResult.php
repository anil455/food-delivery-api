<?php

declare(strict_types=1);

namespace App\Services\Otp;

/**
 * What send-otp hands back to the client.
 *
 * `code` is populated only in local development behind the double gate, and the
 * controller is responsible for omitting it from the response otherwise.
 */
final readonly class OtpDispatchResult
{
    public function __construct(
        public string $verificationId,
        public int $expiresInSeconds,
        public int $resendAfterSeconds,
        public ?string $code = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'verification_id' => $this->verificationId,
            'expires_in' => $this->expiresInSeconds,
            'resend_after' => $this->resendAfterSeconds,
            'otp' => $this->code,
        ], static fn ($value): bool => $value !== null);
    }
}
