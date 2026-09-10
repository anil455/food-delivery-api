<?php

declare(strict_types=1);

namespace App\Services\Otp;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Twilio programmable SMS, for international numbers. */
final class TwilioOtpChannel implements OtpChannel
{
    public function __construct(
        private readonly ?string $sid,
        private readonly ?string $token,
        private readonly ?string $from,
    ) {}

    public function send(string $phone, string $code, string $purpose): void
    {
        if (blank($this->sid) || blank($this->token) || blank($this->from)) {
            throw new OtpDeliveryException(
                'Twilio is selected as the OTP driver but TWILIO_SID, TWILIO_TOKEN or TWILIO_FROM is not set.'
            );
        }

        $ttlMinutes = (int) ceil((int) config('otp.ttl_seconds') / 60);

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->withBasicAuth($this->sid, $this->token)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json", [
                    'To' => $phone,
                    'From' => $this->from,
                    'Body' => "Your verification code is {$code}. It expires in {$ttlMinutes} minutes.",
                ]);
        } catch (Throwable $e) {
            throw new OtpDeliveryException('Twilio request failed: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new OtpDeliveryException('Twilio rejected the request with status '.$response->status());
        }
    }
}
