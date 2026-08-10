<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role/permission remediation follow-up (2026-08-10): the API had zero
 * locale awareness — every error message (validation failures, 403s, 404s,
 * the generic envelope in bootstrap/app.php) was hardcoded English
 * regardless of who was asking, even though the Flutter client is
 * Arabic-first. This is the single place that decision gets made, from the
 * standard `Accept-Language` header the client already controls (see
 * LocaleHeaderInterceptor on the Flutter side) — no new custom header,
 * no per-user stored preference, since the client's active app locale is
 * already the right signal.
 *
 * Deliberately narrow: this app supports exactly two locales (`ar`/`en`,
 * matching lib/l10n/app_{ar,en}.arb on the client). Registered as a global
 * middleware appended early in bootstrap/app.php, so it runs — and
 * app()->setLocale() takes effect — before any FormRequest validation or
 * controller code executes.
 */
class SetLocaleFromHeaderMiddleware
{
    private const SUPPORTED_LOCALES = ['ar', 'en'];

    private const DEFAULT_LOCALE = 'en';

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        // A raw Accept-Language value looks like "ar-IQ,ar;q=0.9,en;q=0.8" —
        // only the two-letter primary subtag of the first entry is used;
        // full RFC 4647 weighted negotiation is unnecessary complexity for
        // a two-locale app.
        $header = (string) $request->header('Accept-Language', '');
        $primary = strtolower(trim(explode(',', $header)[0] ?? ''));
        $primary = explode('-', $primary)[0] ?? '';

        return in_array($primary, self::SUPPORTED_LOCALES, true) ? $primary : self::DEFAULT_LOCALE;
    }
}
