<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\Msg91OtpChannel;
use App\Services\Otp\NullOtpChannel;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\TwilioOtpChannel;
use App\Services\Phone\PhoneNumberService;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Resolves the OtpChannel implementation from config('otp.driver').
 *
 * This binding is the whole of the provider abstraction: switching SMS vendors
 * is a .env change, and nothing outside App\Services\Otp knows which vendor is
 * in use.
 */
class OtpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PhoneNumberService::class);

        $this->app->singleton(OtpChannel::class, function (): OtpChannel {
            $driver = (string) config('otp.driver', 'log');

            return match ($driver) {
                'log' => new LogOtpChannel($this->app->make(PhoneNumberService::class)),
                'array' => new NullOtpChannel,
                'msg91' => new Msg91OtpChannel(
                    config('otp.providers.msg91.auth_key'),
                    config('otp.providers.msg91.template_id'),
                    config('otp.providers.msg91.sender_id'),
                ),
                'twilio' => new TwilioOtpChannel(
                    config('otp.providers.twilio.sid'),
                    config('otp.providers.twilio.token'),
                    config('otp.providers.twilio.from'),
                ),
                default => throw new InvalidArgumentException(
                    "Unknown OTP driver [{$driver}]. Supported: log, array, msg91, twilio."
                ),
            };
        });
    }
}
