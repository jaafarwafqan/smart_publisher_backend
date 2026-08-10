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
     * The Role → Permission matrix. Original shape decided 2026-07-27 (see
     * docs/audit/REMEDIATION_TRACKER.md, Sprint 2 section); re-confirmed and
     * expanded 2026-08-09 per the role/permission remediation spec: viewer
     * is read-only; editor creates/edits its own posts and requests
     * approval but never manages social accounts or publishes directly;
     * manager fully manages social accounts (every social_accounts.x and
     * social_pages.x grant) plus publish/approve; admin holds everything
     * manager does plus member management (via the "all except ownership
     * transfer/delete" pattern below — no separate list needed since Owner
     * already holds every case); owner holds every permission that exists.
     * Fixed role templates only, no per-member permission overrides.
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
                OrganizationPermission::SocialAccountsCreate,
                OrganizationPermission::SocialAccountsUpdate,
                OrganizationPermission::SocialAccountsConnect,
                OrganizationPermission::SocialAccountsDisconnect,
                OrganizationPermission::SocialAccountsDelete,
                OrganizationPermission::SocialAccountsTest,
                OrganizationPermission::SocialAccountsRefresh,
                OrganizationPermission::SocialAccountsSync,
                OrganizationPermission::SocialPagesView,
                OrganizationPermission::SocialPagesSelect,
                OrganizationPermission::SocialPagesSync,
                OrganizationPermission::MembersView,
                OrganizationPermission::AnalyticsView,
                OrganizationPermission::OrganizationView,
            ],

            self::Editor => [
                OrganizationPermission::PostsViewOwn,
                OrganizationPermission::PostsCreate,
                OrganizationPermission::PostsUpdateOwn,
                OrganizationPermission::PostsRequestApproval,
                OrganizationPermission::PostsDeleteOwn,
                OrganizationPermission::SocialAccountsView,
                OrganizationPermission::SocialPagesView,
                OrganizationPermission::MembersView,
                OrganizationPermission::AnalyticsView,
                OrganizationPermission::OrganizationView,
            ],

            self::Viewer => [
                OrganizationPermission::PostsViewAll,
                OrganizationPermission::SocialAccountsView,
                OrganizationPermission::SocialPagesView,
                OrganizationPermission::MembersView,
                OrganizationPermission::AnalyticsView,
                OrganizationPermission::OrganizationView,
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
