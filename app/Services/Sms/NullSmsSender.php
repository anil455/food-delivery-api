<?php

declare(strict_types=1);

namespace App\Services\Sms;

/** Test transport. Discards the message. */
final class NullSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        // Intentionally empty.
    }
}
