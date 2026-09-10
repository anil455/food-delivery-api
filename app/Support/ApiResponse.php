<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ApiErrorCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * The single place a JSON body is shaped. Controllers, the exception handler
 * and middleware all return through here, so a 500 looks structurally identical
 * to a 200.
 */
final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = Response::HTTP_OK,
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => self::resolve($data),
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function created(mixed $data = null, string $message = 'Created successfully'): JsonResponse
    {
        return self::success($data, $message, Response::HTTP_CREATED);
    }

    public static function noContent(string $message = 'Deleted successfully'): JsonResponse
    {
        return self::success(null, $message);
    }

    /**
     * Paginated collections keep their items in `data` and their page state in
     * `meta.pagination`, so clients never have to special-case list endpoints.
     */
    public static function paginated(
        LengthAwarePaginator|AnonymousResourceCollection $paginator,
        string $message = 'OK',
    ): JsonResponse {
        $underlying = $paginator instanceof AnonymousResourceCollection
            ? $paginator->resource
            : $paginator;

        return self::success(
            data: $paginator instanceof AnonymousResourceCollection
                ? $paginator->collection
                : $underlying->items(),
            message: $message,
            meta: [
                'pagination' => [
                    'current_page' => $underlying->currentPage(),
                    'per_page' => $underlying->perPage(),
                    'total' => $underlying->total(),
                    'last_page' => $underlying->lastPage(),
                    'has_more' => $underlying->hasMorePages(),
                ],
            ],
        );
    }

    public static function error(
        string $message,
        ApiErrorCode $code = ApiErrorCode::ServerError,
        int $status = Response::HTTP_BAD_REQUEST,
        array $errors = [],
        array $data = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'code' => $code->value,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($data !== []) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    private static function resolve(mixed $data): mixed
    {
        return $data instanceof JsonResource ? $data->resolve() : $data;
    }
}
