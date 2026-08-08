<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps security headers consistent for API responses and framework errors.
 *
 * The TLS edge repeats these headers so redirects and proxy-generated errors
 * are protected too; keeping them here protects deployments without that
 * edge configuration as well.
 */
class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // PHP's development server adds this itself. Remove it at both the
        // PHP and Symfony layers so a framework/server upgrade is not exposed
        // in API responses.
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()',
        );
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        );

        if ($request->isSecure() && config('security.hsts.enabled')) {
            $hsts = 'max-age='.(int) config('security.hsts.max_age');

            if (config('security.hsts.include_subdomains')) {
                $hsts .= '; includeSubDomains';
            }

            if (config('security.hsts.preload')) {
                $hsts .= '; preload';
            }

            $response->headers->set('Strict-Transport-Security', $hsts);
        }

        return $response;
    }
}
