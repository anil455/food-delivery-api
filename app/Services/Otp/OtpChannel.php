<?php

declare(strict_types=1);

namespace App\Services\Otp;

/**
 * Transport for a one-time code.
 *
 * The provider is chosen by config('otp.driver') and bound in OtpServiceProvider,
 * so swapping MSG91 for Twilio is a .env change with no code change anywhere
 * outside this namespace.
 */
interface OtpChannel
{
    /**
     * @param  string  $phone  E.164
     *
     * @throws OtpDeliveryException when the provider rejects the send
     */
    public function send(string $phone, string $code, string $purpose): void;
}
