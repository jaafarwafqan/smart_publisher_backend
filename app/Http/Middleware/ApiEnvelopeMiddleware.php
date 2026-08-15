<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiEnvelopeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('api/*')) {
            return $response;
        }

        // The OpenAPI document is consumed by external tooling (Swagger UI,
        // Postman, codegen) that expects the raw spec — {openapi, info,
        // paths, ...} — at the top level, not nested under our own
        // success/message/data/errors envelope.
        if ($request->is('api/v1/openapi.json')) {
            return $response;
        }

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload)) {
            return $response;
        }

        if (array_key_exists('success', $payload) && array_key_exists('message', $payload) && array_key_exists('data', $payload) && array_key_exists('errors', $payload)) {
            return $response;
        }

        $status = $response->getStatusCode();
        $isSuccess = $status >= 200 && $status < 400;

        $message = (string) ($payload['message'] ?? ($isSuccess ? 'OK' : 'Request failed'));

        if ($isSuccess) {
            $data = $payload['data'] ?? $payload;
            $meta = $payload['meta'] ?? (object) [];
            $errors = null;
        } else {
            $data = null;
            $meta = (object) [];
            $errors = $payload['errors'] ?? $payload;
        }

        $response->setData([
            'success' => $isSuccess,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
            'errors' => $errors,
        ]);

        return $response;
    }
}
