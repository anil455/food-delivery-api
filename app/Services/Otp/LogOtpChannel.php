<?php

declare(strict_types=1);

namespace App\Services\Otp;

use App\Services\Phone\PhoneNumberService;
use Illuminate\Support\Facades\Log;

/**
 * Local development transport. Writes the code to the log and sends nothing.
 *
 * The phone number is masked even here: development logs get pasted into issues
 * and chat far more often than anyone intends.
 */
final class LogOtpChannel implements OtpChannel
{
    public function __construct(private readonly PhoneNumberService $phones) {}

    public function send(string $phone, string $code, string $purpose): void
    {
        Log::channel(config('logging.default'))->info('OTP generated', [
            'phone' => $this->phones->mask($phone),
            'purpose' => $purpose,
            'code' => $code,
        ]);
    }
}
