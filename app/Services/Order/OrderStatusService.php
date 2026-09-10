<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\ApiErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\Api\OrderException;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusChanged;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The order lifecycle.
 *
 * Every transition goes through here, so the state machine in OrderStatus is the
 * only definition of what is legal, and every change leaves an audit row naming
 * who made it.
 */
final class OrderStatusService
{
    /**
     * Move an order to a new status.
     *
     * @throws OrderException when the transition is not allowed
     */
    public function transition(Order $order, OrderStatus $to, ?User $actor = null, ?string $note = null): Order
    {
        $from = $order->status;

        if (! $from->canTransitionTo($to)) {
            throw new OrderException(
                "An order that is {$from->label()} cannot become {$to->label()}.",
                ApiErrorCode::InvalidStatusTransition,
                Response::HTTP_CONFLICT,
                [
                    'current_status' => $from->value,
                    'requested_status' => $to->value,
                    'allowed' => array_map(
                        static fn (OrderStatus $status): string => $status->value,
                        $from->allowedTransitions(),
                    ),
                ],
            );
        }

        DB::transaction(function () use ($order, $from, $to, $actor, $note): void {
            $order->forceFill([
                'status' => $to,
                ...$this->timestampFor($to, $actor, $note),
            ])->save();

            $this->record($order, $from, $to, $actor, $note);
        });

        $this->notifyCustomer($order, $to);

        return $order;
    }

    /**
     * Notify after the transaction commits, never inside it.
     *
     * A queued notification dispatched inside a transaction can be picked up by
     * a worker before the commit lands, and would then read a status that does
     * not exist yet.
     */
    private function notifyCustomer(Order $order, OrderStatus $to): void
    {
        if (! OrderStatusChanged::isWorthSending($to)) {
            return;
        }

        $order->loadMissing(['restaurant', 'user']);

        $order->user?->notify(new OrderStatusChanged($order, $to));
    }

    /** Write the audit row. Public so order placement can log the initial state. */
    public function record(
        Order $order,
        ?OrderStatus $from,
        OrderStatus $to,
        ?User $actor = null,
        ?string $note = null,
    ): void {
        $order->statusHistories()->create([
            'restaurant_id' => $order->restaurant_id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by_user_id' => $actor?->getKey(),
            'note' => $note,
        ]);
    }

    /**
     * Milestone timestamps live on the order itself so the common queries do
     * not have to join the history table.
     */
    private function timestampFor(OrderStatus $to, ?User $actor, ?string $note): array
    {
        return match ($to) {
            OrderStatus::Confirmed => ['confirmed_at' => now()],
            OrderStatus::Delivered => ['delivered_at' => now()],
            OrderStatus::Cancelled, OrderStatus::Rejected => [
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor?->getKey(),
                'cancellation_reason' => $note,
            ],
            default => [],
        };
    }
}
