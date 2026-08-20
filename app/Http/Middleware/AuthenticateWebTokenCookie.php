<?php

namespace App\Http\Middleware;

use App\Support\Auth\WebTokenCookies;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bridges an httpOnly browser cookie into Sanctum's normal bearer guard. */
final class AuthenticateWebTokenCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(WebTokenCookies::class)->requested($request) && ! $request->bearerToken()) {
            $token = $request->cookie(WebTokenCookies::ACCESS_COOKIE);
            if (is_string($token) && $token !== '') {
                $request->headers->set('Authorization', 'Bearer '.$token);
            }
        }

        return $next($request);
    }
}
