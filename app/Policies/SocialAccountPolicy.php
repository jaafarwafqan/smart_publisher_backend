<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Models\SocialAccount;
use App\Models\User;

/**
 * Role/permission remediation (2026-08-09): previously every one of these
 * methods except view/delete checked the single social_accounts.connect
 * permission — connecting, updating, testing, and refreshing a token were
 * indistinguishable grants. Each method below now checks its own narrow
 * OrganizationPermission case so a role template can grant, say, "test the
 * connection" without also granting "delete the account".
 */
class SocialAccountPolicy
{
    public function view(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialAccountsView,
        );
    }

    public function update(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialAccountsUpdate,
        );
    }

    /**
     * Permanent removal (SocialAccountController::destroy(), deletes the
     * row). Distinct from changeStatus()/SocialAccountsDisconnect below,
     * which deactivates an account without deleting it.
     */
    public function delete(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialAccountsDelete,
        );
    }

    public function refreshToken(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialAccountsRefresh,
        );
    }

    public function testConnection(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialAccountsTest,
        );
    }

    /**
     * SocialAccountController::setStatus() — the "disconnect without
     * deleting" action (e.g. marking an account revoked/inactive so it
     * stops being usable for publishing while keeping its history).
     */
    public function changeStatus(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialAccountsDisconnect,
        );
    }

    public function viewPages(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialPagesView,
        );
    }

    /**
     * Discovering/adding/removing pages (SocialAccountController::syncPages(),
     * addPage(), destroyPage()) — the page *set* changes. Distinct from
     * selectPages() below, which only changes which already-discovered
     * pages are marked selected for publishing.
     */
    public function syncPages(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialPagesSync,
        );
    }

    public function selectPages(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialPagesSelect,
        );
    }
}
