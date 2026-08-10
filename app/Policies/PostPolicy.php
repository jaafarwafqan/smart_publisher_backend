<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function view(User $user, Post $post): bool
    {
        if ($user->id === $post->user_id
            && $user->hasOrganizationPermission($post->organization_id, OrganizationPermission::PostsViewOwn)) {
            return true;
        }
        if ($user->hasOrganizationPermission($post->organization_id, OrganizationPermission::PostsViewAll)) {
            return true;
        }

        return false;
    }

    public function update(User $user, Post $post): bool
    {
        if ($user->id === $post->user_id
            && $user->hasOrganizationPermission($post->organization_id, OrganizationPermission::PostsUpdateOwn)) {
            return true;
        }
        if ($user->hasOrganizationPermission($post->organization_id, OrganizationPermission::PostsUpdateAll)) {
            return true;
        }

        return false;
    }

    public function delete(User $user, Post $post): bool
    {
        if ($user->id === $post->user_id
            && $user->hasOrganizationPermission($post->organization_id, OrganizationPermission::PostsDeleteOwn)) {
            return true;
        }
        if ($user->hasOrganizationPermission($post->organization_id, OrganizationPermission::PostsDeleteAll)) {
            return true;
        }

        return false;
    }

    /**
     * Grants the ability to *request* a publish/schedule action — whether it
     * executes immediately or lands as pending-approval is decided by
     * PostController via canPublishDirectly(), not here. A user with no
     * publish-adjacent capability at all (e.g. viewer) can't even submit a
     * request. PostsRequestApproval is the explicit grant for "submit for
     * approval" (editor's role); ownership + PostsUpdateOwn is kept as a
     * fallback so a role holding update-own without the newer explicit
     * grant isn't locked out.
     */
    public function publish(User $user, Post $post): bool
    {
        if ($user->id === $post->user_id
            && ($user->hasOrganizationPermission($post->organization_id, OrganizationPermission::PostsRequestApproval)
                || $user->hasOrganizationPermission($post->organization_id, OrganizationPermission::PostsUpdateOwn))) {
            return true;
        }
        if ($user->hasOrganizationPermission($post->organization_id, OrganizationPermission::PostsPublish)) {
            return true;
        }

        return false;
    }

    /**
     * Approving/rejecting a pending post is never tied to ownership — an
     * approver reviewing their own post makes no sense as a control.
     */
    public function approve(User $user, Post $post): bool
    {
        return $user->hasOrganizationPermission($post->organization_id, OrganizationPermission::PostsApprove);
    }
}
