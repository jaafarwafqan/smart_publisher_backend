<?php

namespace App\Support\Platform;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\User;

class PlatformAdministrationGuard
{
    /**
     * Must be called after a platform-role or account-status change inside
     * the same database transaction. The row locks make concurrent attempts
     * to revoke the last active platform administrator deterministic.
     */
    public function assertAtLeastOneActiveSuperAdmin(): void
    {
        $activeSuperAdmins = User::query()
            ->where('is_super_admin', true)
            ->where('is_active', true)
            ->lockForUpdate()
            ->count();

        if ($activeSuperAdmins === 0) {
            abort(422, 'The platform must retain at least one active super administrator.');
        }
    }

    /** @param array<int, int> $organizationIds */
    public function assertOrganizationsRetainActiveOwner(array $organizationIds): void
    {
        foreach (array_values(array_unique($organizationIds)) as $organizationId) {
            $activeOwners = OrganizationMembership::query()
                ->where('organization_id', $organizationId)
                ->where('role', OrganizationRole::Owner)
                ->where('status', 'active')
                ->whereHas('user', fn ($query) => $query->where('is_active', true))
                ->lockForUpdate()
                ->count();

            if ($activeOwners === 0) {
                abort(422, 'An organization must retain at least one active owner.');
            }
        }
    }
}
