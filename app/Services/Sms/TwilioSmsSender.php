<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Twilio programmable SMS, for international numbers. */
final class TwilioSmsSender implements SmsSender
{
    public function __construct(
        private readonly ?string $sid,
        private readonly ?string $token,
        private readonly ?string $from,
    ) {}

    public function send(string $phone, string $message): void
    {
        if (blank($this->sid) || blank($this->token) || blank($this->from)) {
            throw new SmsDeliveryException('Twilio is the SMS driver but its credentials are not set.');
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->withBasicAuth($this->sid, $this->token)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json", [
                    'To' => $phone,
                    'From' => $this->from,
                    'Body' => $message,
                ]);
        } catch (Throwable $e) {
            throw new SmsDeliveryException('Twilio request failed: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new SmsDeliveryException('Twilio rejected the request with status '.$response->status());
        }
    }
}
