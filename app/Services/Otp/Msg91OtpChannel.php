<?php

declare(strict_types=1);

namespace App\Services\Otp;

use Illuminate\Support\Facades\Http;
use Throwable;

/** MSG91 transactional SMS. Credentials come from config, never from code. */
final class Msg91OtpChannel implements OtpChannel
{
    public function __construct(
        private readonly ?string $authKey,
        private readonly ?string $templateId,
        private readonly ?string $senderId,
    ) {}

    public function send(string $phone, string $code, string $purpose): void
    {
        if (blank($this->authKey) || blank($this->templateId)) {
            throw new OtpDeliveryException(
                'MSG91 is selected as the OTP driver but MSG91_AUTH_KEY or MSG91_TEMPLATE_ID is not set.'
            );
        }

        try {
            $response = Http::asJson()
                ->timeout(10)
                ->withHeaders(['authkey' => $this->authKey])
                ->post('https://control.msg91.com/api/v5/flow/', [
                    'template_id' => $this->templateId,
                    'sender' => $this->senderId,
                    'short_url' => '0',
                    'recipients' => [[
                        'mobiles' => ltrim($phone, '+'),
                        'otp' => $code,
                    ]],
                ]);
        } catch (Throwable $e) {
            throw new OtpDeliveryException('MSG91 request failed: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new OtpDeliveryException('MSG91 rejected the request with status '.$response->status());
        }
    }
}
