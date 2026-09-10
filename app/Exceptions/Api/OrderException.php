<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

use App\Enums\ApiErrorCode;

/**
 * Expected order failures: a closed kitchen, an address outside the delivery
 * radius, an illegal status transition. Each carries a code and, where useful,
 * the data the client needs to explain itself to the customer.
 */
final class OrderException extends ApiException
{
    public function __construct(string $message, ApiErrorCode $code, int $status, array $payload = [])
    {
        parent::__construct($message, $code, $status, $payload);
    }
}
