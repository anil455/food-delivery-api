<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Services\Sms\SmsDeliveryException;
use App\Services\Sms\SmsSender;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Wires SmsSender into Laravel notifications, so an order update is written the
 * same way as any other notification and can gain email or push later without
 * touching the code that triggers it.
 */
final class SmsChannel
{
    public function __construct(private readonly SmsSender $sender) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! config('sms.enabled')) {
            return;
        }

        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $phone = $notifiable->routeNotificationFor('sms', $notification);

        if (blank($phone)) {
            return;
        }

        $message = (string) $notification->toSms($notifiable);

        if (trim($message) === '') {
            return;
        }

        try {
            $this->sender->send($phone, $message);
        } catch (SmsDeliveryException $e) {
            // A failed order-status text must never roll back the status change
            // that triggered it. Log and move on.
            Log::warning('SMS notification failed', [
                'notification' => $notification::class,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
