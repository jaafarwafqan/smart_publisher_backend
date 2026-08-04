<?php

namespace App\Support\Tenancy;

use App\Models\User;

/**
 * The actual "which organization should be active for this user" logic,
 * shared by ResolveTenantContext (HTTP middleware, runs after auth:sanctum)
 * and AuthController::login()/refresh() (which have the authenticated user
 * in hand *before* any route middleware could apply — login is definitionally
 * a pre-auth request, so it cannot rely on the 'tenant' middleware and must
 * resolve context for itself before touching any tenant-scoped relation like
 * User::socialAccounts()).
 */
class TenantContextResolver
{
    public function resolveAndSet(User $user, ?int $requestedOrganizationId = null): int
    {
        $organizationId = null;

        if ($requestedOrganizationId !== null && $user->isMemberOf($requestedOrganizationId)) {
            $organizationId = $requestedOrganizationId;
        } elseif ($user->current_organization_id !== null && $user->isMemberOf((int) $user->current_organization_id)) {
            $organizationId = (int) $user->current_organization_id;
        } else {
            $fallback = $user->memberships()->where('status', 'active')->orderBy('id')->first();
            $organizationId = $fallback?->organization_id;
        }

        if ($organizationId === null) {
            throw new NoOrganizationMembershipException;
        }

        app(TenantContext::class)->set($organizationId);

        if ($user->current_organization_id !== $organizationId) {
            $user->forceFill(['current_organization_id' => $organizationId])->save();
        }

        return $organizationId;
    }
}
