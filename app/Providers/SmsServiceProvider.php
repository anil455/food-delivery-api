<?php

declare(strict_types=1);

namespace App\Providers;

use App\Notifications\Channels\SmsChannel;
use App\Services\Phone\PhoneNumberService;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\Msg91SmsSender;
use App\Services\Sms\NullSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\Sms\TwilioSmsSender;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Resolves the SMS transport from config('sms.driver') and registers the
 * notification channel that uses it.
 */
class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsSender::class, function (): SmsSender {
            $driver = (string) config('sms.driver', 'log');

            return match ($driver) {
                'log' => new LogSmsSender($this->app->make(PhoneNumberService::class)),
                'array' => new NullSmsSender,
                'msg91' => new Msg91SmsSender(
                    config('sms.providers.msg91.auth_key'),
                    config('sms.providers.msg91.sender_id'),
                ),
                'twilio' => new TwilioSmsSender(
                    config('sms.providers.twilio.sid'),
                    config('sms.providers.twilio.token'),
                    config('sms.providers.twilio.from'),
                ),
                default => throw new InvalidArgumentException(
                    "Unknown SMS driver [{$driver}]. Supported: log, array, msg91, twilio."
                ),
            };
        });
    }

    public function boot(): void
    {
        Notification::extend(SmsChannel::class, fn ($app) => $app->make(SmsChannel::class));
    }
}
