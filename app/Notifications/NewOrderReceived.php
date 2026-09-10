<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells the restaurant a new order has landed.
 *
 * Sent on-demand to the restaurant phone rather than to a user, because a
 * kitchen is a place, not an account: whoever is holding the handset should see
 * it, regardless of who is logged into the dashboard.
 */
final class NewOrderReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(private readonly Order $order)
    {
        $this->onQueue('notifications');
    }

    public function via(mixed $notifiable): array
    {
        return [SmsChannel::class];
    }

    public function toSms(mixed $notifiable): string
    {
        $number = $this->order->order_number;
        $total = $this->order->grand_total->toMajorString();
        $items = $this->order->items->sum('quantity');
        $sender = config('sms.sender_name');

        return "{$sender}: New order {$number} - {$items} item(s), {$total}. Open your dashboard to accept it.";
    }
}
