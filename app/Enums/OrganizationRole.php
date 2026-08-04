<?php

namespace App\Enums;

/**
 * Membership role within a single organization — deliberately separate from
 * the app-wide Spatie roles (admin/manager/editor) on User, which predate
 * the tenant model and still govern platform-level permissions (e.g.
 * managing OAuth provider settings). A user can be `owner` of one
 * organization and `viewer` of another at the same time.
 *
 * Sprint 2: roles are templates that grant a fixed set of
 * OrganizationPermission values (see permissions() below) — Policies and
 * Controllers must check the PERMISSION via hasPermission(), never branch
 * on the role name directly, so the grant matrix can change in one place.
 */
enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Editor = 'editor';
    case Viewer = 'viewer';

    /**
     * The Role → Permission matrix. Decided 2026-07-27 per explicit product
     * answers (see docs/audit/REMEDIATION_TRACKER.md, Sprint 2 section):
     * editor sees only their own posts; manager fully manages social
     * accounts; editor's publish/schedule requires manager/admin/owner
     * approval (posts.publish withheld from editor on purpose — see
     * PostController's approval-workflow gating); fixed role templates
     * only, no per-member permission overrides.
     *
     * @return array<int, OrganizationPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => OrganizationPermission::cases(),

            self::Admin => array_filter(
                OrganizationPermission::cases(),
                fn (OrganizationPermission $permission) => ! in_array($permission, [
                    OrganizationPermission::OrganizationTransferOwnership,
                    OrganizationPermission::OrganizationDelete,
                ], true),
            ),

            self::Manager => [
                OrganizationPermission::PostsViewAll,
                OrganizationPermission::PostsCreate,
                OrganizationPermission::PostsUpdateOwn,
                OrganizationPermission::PostsUpdateAll,
                OrganizationPermission::PostsApprove,
                OrganizationPermission::PostsPublish,
                OrganizationPermission::PostsDeleteOwn,
                OrganizationPermission::SocialAccountsView,
                OrganizationPermission::SocialAccountsConnect,
                OrganizationPermission::SocialAccountsDisconnect,
                OrganizationPermission::SocialPagesManage,
                OrganizationPermission::MembersView,
                OrganizationPermission::AnalyticsView,
            ],

            self::Editor => [
                OrganizationPermission::PostsViewOwn,
                OrganizationPermission::PostsCreate,
                OrganizationPermission::PostsUpdateOwn,
                OrganizationPermission::PostsDeleteOwn,
                OrganizationPermission::SocialAccountsView,
                OrganizationPermission::MembersView,
                OrganizationPermission::AnalyticsView,
            ],

            self::Viewer => [
                OrganizationPermission::PostsViewAll,
                OrganizationPermission::SocialAccountsView,
                OrganizationPermission::MembersView,
                OrganizationPermission::AnalyticsView,
            ],
        };
    }

    public function hasPermission(OrganizationPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Only the owner can delete the organization itself or transfer
     * ownership — never derived from "admin" to avoid an admin locking out
     * the actual owner. Kept as an explicit check (not just
     * hasPermission(OrganizationTransferOwnership)) because last-owner
     * protection logic needs to identify "is this literally the owner
     * role" independent of the permission matrix.
     */
    public function isOwner(): bool
    {
        return $this === self::Owner;
    }
}
