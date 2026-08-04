<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Models\MediaAttachment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Media has no dedicated permission set in the Sprint 2 matrix — it rides
 * on the closest posts.* equivalent, since attachments are always
 * post-adjacent content in this product.
 */
class MediaAttachmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasCurrentOrganizationPermission($user, OrganizationPermission::PostsViewOwn)
            || $this->hasCurrentOrganizationPermission($user, OrganizationPermission::PostsViewAll);
    }

    public function create(User $user): bool
    {
        return $this->hasCurrentOrganizationPermission($user, OrganizationPermission::PostsCreate);
    }

    public function view(User $user, MediaAttachment $mediaAttachment): bool
    {
        return ($user->id === $mediaAttachment->user_id
                && $user->hasOrganizationPermission($mediaAttachment->organization_id, OrganizationPermission::PostsViewOwn))
            || $user->hasOrganizationPermission($mediaAttachment->organization_id, OrganizationPermission::PostsViewAll);
    }

    public function delete(User $user, MediaAttachment $mediaAttachment): bool
    {
        return ($user->id === $mediaAttachment->user_id
                && $user->hasOrganizationPermission($mediaAttachment->organization_id, OrganizationPermission::PostsDeleteOwn))
            || $user->hasOrganizationPermission($mediaAttachment->organization_id, OrganizationPermission::PostsDeleteAll);
    }

    public function update(User $user, MediaAttachment $mediaAttachment): bool
    {
        return ($user->id === $mediaAttachment->user_id
                && $user->hasOrganizationPermission($mediaAttachment->organization_id, OrganizationPermission::PostsUpdateOwn))
            || $user->hasOrganizationPermission($mediaAttachment->organization_id, OrganizationPermission::PostsUpdateAll);
    }

    private function hasCurrentOrganizationPermission(User $user, OrganizationPermission $permission): bool
    {
        return $user->hasOrganizationPermission(app(TenantContext::class)->get(), $permission);
    }
}
