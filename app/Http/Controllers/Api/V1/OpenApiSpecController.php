<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Fixes the 2026-08-12 audit finding: GET /api/v1/openapi.json returned 404
 * — not a functional bug, but a real gap in testability/contract
 * documentation. Serves a static, hand-maintained OpenAPI 3.0 document
 * (resources/openapi/openapi.json) rather than generating one at runtime —
 * simpler, and matches the audit's own suggestion ("static OpenAPI or a
 * protected docs page, as needed"). Deliberately public/unauthenticated:
 * the spec only lists paths, summaries, and schemas already discoverable
 * from routes/api.php in this open-source-style repo — no secrets.
 */
class OpenApiSpecController extends Controller
{
    public function show(): JsonResponse
    {
        $path = resource_path('openapi/openapi.json');

        if (! is_file($path)) {
            throw new RuntimeException('OpenAPI spec file is missing at '.$path);
        }

        $spec = json_decode(file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        // Bypasses ApiEnvelopeMiddleware's success/message/data/errors wrap
        // on purpose — this is a standard OpenAPI document consumed by
        // tooling (Swagger UI, Postman, codegen) that expects the raw spec
        // at the top level, not our own API's response envelope.
        return response()->json($spec)
            ->header('Cache-Control', 'public, max-age=300');
    }
}
