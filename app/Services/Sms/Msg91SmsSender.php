<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Throwable;

/** MSG91 transactional SMS. Credentials come from config, never from code. */
final class Msg91SmsSender implements SmsSender
{
    public function __construct(
        private readonly ?string $authKey,
        private readonly ?string $senderId,
    ) {}

    public function send(string $phone, string $message): void
    {
        if (blank($this->authKey)) {
            throw new SmsDeliveryException('MSG91 is the SMS driver but MSG91_AUTH_KEY is not set.');
        }

        try {
            $response = Http::asJson()
                ->timeout(10)
                ->withHeaders(['authkey' => $this->authKey])
                ->post('https://control.msg91.com/api/v5/message', [
                    'sender' => $this->senderId,
                    'route' => '4',
                    'sms' => [[
                        'message' => $message,
                        'to' => [ltrim($phone, '+')],
                    ]],
                ]);
        } catch (Throwable $e) {
            throw new SmsDeliveryException('MSG91 request failed: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new SmsDeliveryException('MSG91 rejected the request with status '.$response->status());
        }
    }
}
