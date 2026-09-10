<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Order;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Human-readable, non-guessable order numbers: ORD-20260908-7K3PQ2.
 *
 * The date makes them easy to talk about on a support call; the random tail
 * makes them impossible to enumerate, which matters because order numbers end
 * up in URLs, emails and SMS.
 */
final class OrderNumberGenerator
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function generate(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = sprintf('ORD-%s-%s', now()->format('Ymd'), $this->tail(6));

            // The unique index is the real guarantee; this check just avoids
            // burning a transaction on a collision.
            $taken = Order::withoutTenantScope()
                ->where('order_number', $candidate)
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not generate a unique order number after 10 attempts.');
    }

    /** Ambiguous characters (0/O, 1/I) are excluded so numbers survive being read aloud. */
    private function tail(int $length): string
    {
        $alphabet = self::ALPHABET;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        unset($length);

        return $out;
    }
}
