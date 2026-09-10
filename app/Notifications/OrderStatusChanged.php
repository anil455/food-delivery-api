<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells the customer their order moved.
 *
 * Only the transitions a customer actually cares about produce a message.
 * Texting on every internal step trains people to ignore the ones that matter,
 * and each message costs money.
 */
final class OrderStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(
        private readonly Order $order,
        private readonly OrderStatus $status,
    ) {
        $this->onQueue('notifications');
    }

    /** Statuses worth a text. Preparing and ready-for-pickup are not. */
    public static function isWorthSending(OrderStatus $status): bool
    {
        return in_array($status, [
            OrderStatus::Confirmed,
            OrderStatus::OutForDelivery,
            OrderStatus::Delivered,
            OrderStatus::Cancelled,
            OrderStatus::Rejected,
        ], true);
    }

    public function via(mixed $notifiable): array
    {
        return [SmsChannel::class];
    }

    public function toSms(mixed $notifiable): string
    {
        $restaurant = $this->order->restaurant?->name ?? 'the restaurant';
        $number = $this->order->order_number;
        $sender = config('sms.sender_name');

        $body = match ($this->status) {
            OrderStatus::Confirmed => "{$restaurant} has accepted your order {$number}."
                .($this->order->estimated_minutes !== null
                    ? " Estimated arrival in about {$this->order->estimated_minutes} minutes."
                    : ''),

            OrderStatus::OutForDelivery => "Your order {$number} from {$restaurant} is on its way.",

            OrderStatus::Delivered => "Your order {$number} has been delivered. Enjoy your meal.",

            OrderStatus::Cancelled => "Your order {$number} has been cancelled."
                .($this->order->cancellation_reason !== null
                    ? " Reason: {$this->order->cancellation_reason}."
                    : '')
                .' Any payment made will be refunded.',

            OrderStatus::Rejected => "{$restaurant} could not accept your order {$number}."
                .' Any payment made will be refunded.',

            default => '',
        };

        return $body === '' ? '' : "{$sender}: {$body}";
    }
}
