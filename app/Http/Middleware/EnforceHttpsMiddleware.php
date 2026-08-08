<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject plain HTTP before an API route can receive a bearer token.
 *
 * TLS is terminated by Caddy in the container deployment. Laravel sees the
 * original scheme through X-Forwarded-Proto, but only after the configured
 * internal proxy has been trusted in bootstrap/app.php.
 */
class EnforceHttpsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('security.require_https') && ! $request->isSecure()) {
            abort(400, 'HTTPS is required for this environment.');
        }

        return $next($request);
    }
}
