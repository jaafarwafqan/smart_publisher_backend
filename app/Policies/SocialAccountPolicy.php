<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Models\SocialAccount;
use App\Models\User;

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
            OrganizationPermission::SocialAccountsConnect,
        );
    }

    public function delete(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialAccountsDisconnect,
        );
    }

    public function refreshToken(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialAccountsConnect,
        );
    }

    public function testConnection(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialAccountsConnect,
        );
    }

    public function changeStatus(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialAccountsConnect,
        );
    }

    public function viewPages(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialAccountsView,
        );
    }

    public function managePages(User $user, SocialAccount $socialAccount): bool
    {
        return $user->hasOrganizationPermission(
            $socialAccount->organization_id,
            OrganizationPermission::SocialPagesManage,
        );
    }
}
