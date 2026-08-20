<?php

namespace App\Support\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Browser-only transport for Sanctum's opaque personal-access tokens. The
 * Flutter Web client asks for this explicitly and never receives either raw
 * token in JSON or browser storage; JavaScript only retains a non-sensitive
 * session marker while the browser manages the httpOnly cookies.
 */
final class WebTokenCookies
{
    public const ACCESS_COOKIE = 'sp_access_token';

    public const REFRESH_COOKIE = 'sp_refresh_token';

    public function requested(Request $request): bool
    {
        return (bool) config('auth.web_token_cookies.enabled')
            && $request->header('X-SP-Web-Client') === '1';
    }

    /** @param array{access_token: string, refresh_token: string, expires_in: int|float} $tokens */
    public function attach(Request $request, JsonResponse $response, array $tokens): JsonResponse
    {
        if (! $this->requested($request)) {
            return $response;
        }

        $payload = $response->getData(true);
        if (is_array($payload)) {
            $payload['access_token'] = '';
            $payload['refresh_token'] = '';
            $response->setData($payload);
        }

        return $response
            ->withCookie($this->cookie(self::ACCESS_COOKIE, $tokens['access_token'], max(1, (int) ceil($tokens['expires_in'] / 60))))
            ->withCookie($this->cookie(self::REFRESH_COOKIE, $tokens['refresh_token'], max(1, (int) config('auth.refresh_token_days', 30) * 24 * 60)));
    }

    public function forget(Request $request, JsonResponse $response): JsonResponse
    {
        if (! $this->requested($request)) {
            return $response;
        }

        return $response
            ->withoutCookie(self::ACCESS_COOKIE, $this->path(), $this->domain())
            ->withoutCookie(self::REFRESH_COOKIE, $this->path(), $this->domain());
    }

    private function cookie(string $name, string $value, int $minutes): Cookie
    {
        return cookie(
            $name,
            $value,
            $minutes,
            $this->path(),
            $this->domain(),
            (bool) config('auth.web_token_cookies.secure'),
            true,
            false,
            (string) config('auth.web_token_cookies.same_site'),
        );
    }

    private function path(): string
    {
        return (string) config('auth.web_token_cookies.path', '/api/v1');
    }

    private function domain(): ?string
    {
        $domain = config('auth.web_token_cookies.domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }
}
