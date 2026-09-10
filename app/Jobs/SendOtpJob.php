<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Otp\OtpChannel;
use App\Services\Otp\OtpDeliveryException;
use App\Services\Phone\PhoneNumberService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Hands a code to the configured provider.
 *
 * Queued so a slow or unreachable SMS gateway cannot hold up the HTTP response.
 * The plaintext code lives in the payload for the life of the job only, which is
 * why the queue must be a trusted store in production.
 */
final class SendOtpJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 15];

    public function __construct(
        private readonly string $phone,
        private readonly string $code,
        private readonly string $purpose,
    ) {
        $this->onQueue('otp');
    }

    public function handle(OtpChannel $channel): void
    {
        $channel->send($this->phone, $this->code, $this->purpose);
    }

    public function failed(?\Throwable $exception): void
    {
        // The number is masked and the code never logged: a failure report must
        // not become a way to read live codes out of the log.
        Log::error('OTP delivery failed after retries', [
            'phone' => app(PhoneNumberService::class)->mask($this->phone),
            'purpose' => $this->purpose,
            'reason' => $exception instanceof OtpDeliveryException
                ? $exception->getMessage()
                : 'unknown',
        ]);
    }
}
