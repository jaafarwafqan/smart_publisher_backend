<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestContextMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());
        $correlationId = (string) ($request->header('X-Correlation-ID') ?: $requestId);
        $traceId = (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('correlation_id', $correlationId);
        $request->attributes->set('trace_id', $traceId);

        Log::withContext([
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'trace_id' => $traceId,
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        $startedAt = microtime(true);

        $response = $next($request);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        // TenantContext is set by 'tenant' route middleware, which runs
        // between this middleware's "before" and "after" halves — so it's
        // available now for any authenticated, tenant-scoped request, but
        // absent for guest routes (login, health check).
        $tenantContext = app(TenantContext::class);

        Log::info('api.request.completed', [
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'organization_id' => $tenantContext->has() ? $tenantContext->get() : null,
        ]);

        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Correlation-ID', $correlationId);
        $response->headers->set('X-Trace-ID', $traceId);
        $response->headers->set('X-Request-Duration-Ms', (string) $durationMs);

        return $response;
    }
}
