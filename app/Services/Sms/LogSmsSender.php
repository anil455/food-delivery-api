<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\Phone\PhoneNumberService;
use Illuminate\Support\Facades\Log;

/** Local development: writes the message to the log and sends nothing. */
final class LogSmsSender implements SmsSender
{
    public function __construct(private readonly PhoneNumberService $phones) {}

    public function send(string $phone, string $message): void
    {
        // The number is masked even here: development logs get pasted around.
        Log::info('SMS', [
            'to' => $this->phones->mask($phone),
            'message' => $message,
        ]);
    }
}
