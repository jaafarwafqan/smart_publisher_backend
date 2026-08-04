<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * @param  array<string, mixed>|array<int, mixed>|null  $data
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>|array<int, mixed>|null  $errors
     */
    public static function make(
        bool $success,
        string $message,
        ?array $data = null,
        ?array $meta = null,
        ?array $errors = null,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'meta' => $meta ?? (object) [],
            'errors' => $errors,
        ], $status);
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>|null  $data
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(string $message = 'OK', ?array $data = null, ?array $meta = null, int $status = 200): JsonResponse
    {
        return self::make(true, $message, $data, $meta, null, $status);
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>|null  $errors
     */
    public static function error(string $message = 'Error', ?array $errors = null, int $status = 400): JsonResponse
    {
        return self::make(false, $message, null, null, $errors, $status);
    }
}
