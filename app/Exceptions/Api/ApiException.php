<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

use App\Enums\ApiErrorCode;
use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base for every expected, client-facing failure.
 *
 * Each subclass carries its own error code, HTTP status and optional structured
 * payload, and renders itself — so the handler needs no per-exception mapping.
 */
abstract class ApiException extends Exception
{
    public function __construct(
        string $message,
        protected ApiErrorCode $errorCode,
        protected int $status,
        protected array $payload = [],
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error(
            message: $this->getMessage(),
            code: $this->errorCode,
            status: $this->status,
            data: $this->payload,
        );
    }

    public function errorCode(): ApiErrorCode
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
