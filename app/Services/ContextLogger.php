<?php

namespace App\Services;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContextLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $event, array $context = [], ?Request $request = null): void
    {
        Log::info($event, self::withRequestContext($context, $request));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $event, array $context = [], ?Request $request = null): void
    {
        Log::warning($event, self::withRequestContext($context, $request));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $event, array $context = [], ?Request $request = null): void
    {
        Log::error($event, self::withRequestContext($context, $request));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function withRequestContext(array $context, ?Request $request = null): array
    {
        $tenantContext = app(TenantContext::class);

        $merged = $tenantContext->has()
            ? array_merge(['organization_id' => $tenantContext->get()], $context)
            : $context;

        if (! $request) {
            return $merged;
        }

        return array_merge([
            'request_id' => $request->attributes->get('request_id'),
            'correlation_id' => $request->attributes->get('correlation_id'),
            'trace_id' => $request->attributes->get('trace_id'),
        ], $merged);
    }
}
