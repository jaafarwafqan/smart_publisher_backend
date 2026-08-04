<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\NoOrganizationMembershipException;
use App\Support\Tenancy\TenantContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active organization for this request and sets TenantContext
 * before the controller runs. Runs after auth:sanctum in the route
 * middleware stack, so $request->user() is always available here.
 *
 * Trust boundary (CTO audit Sprint 1 instruction: "don't accept
 * organization_id from the request as directly trusted"): the client MAY
 * suggest which organization it wants active via the X-Organization-Id
 * header, but that suggestion is only honored after verifying the
 * authenticated user actually holds an active membership in it. An invalid
 * or missing header falls back to the user's last-used organization, then
 * their first active membership, in that order — never to "all
 * organizations" or "no filter."
 */
class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $requestedId = $request->header('X-Organization-Id');
        $requestedId = ($requestedId !== null && ctype_digit((string) $requestedId)) ? (int) $requestedId : null;

        try {
            app(TenantContextResolver::class)->resolveAndSet($user, $requestedId);
        } catch (NoOrganizationMembershipException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return $next($request);
    }
}
