<?php

namespace App\Enums;

/**
 * The granular capability vocabulary Sprint 2 introduces — roles are
 * templates that grant a fixed set of these (see RolePermissionMatrix),
 * but Policies/Controllers must check the PERMISSION, never the role name
 * directly, so the mapping can change without touching enforcement code.
 */
enum OrganizationPermission: string
{
    case PostsViewOwn = 'posts.view_own';
    case PostsViewAll = 'posts.view_all';
    case PostsCreate = 'posts.create';
    case PostsUpdateOwn = 'posts.update_own';
    case PostsUpdateAll = 'posts.update_all';
    // Grants the ability to *request* a publish/schedule action that isn't
    // executed directly — distinct from PostsPublish (the direct-execution
    // gate). Added in the role/permission remediation round so this is a
    // real, checkable capability instead of only being inferred client-side
    // from "has update_own but not publish" (see PostPolicy::publish()).
    case PostsRequestApproval = 'posts.request_approval';
    case PostsApprove = 'posts.approve';
    case PostsPublish = 'posts.publish';
    case PostsDeleteOwn = 'posts.delete_own';
    case PostsDeleteAll = 'posts.delete_all';

    // Role/permission remediation: social_accounts.connect previously
    // gated create/update/test/refresh/status-change all at once (see
    // SocialAccountPolicy's prior single-permission checks). Split into
    // the granular set below so each controller action has its own,
    // narrowly-scoped grant.
    case SocialAccountsView = 'social_accounts.view';
    case SocialAccountsCreate = 'social_accounts.create';
    case SocialAccountsUpdate = 'social_accounts.update';
    case SocialAccountsConnect = 'social_accounts.connect';
    case SocialAccountsDisconnect = 'social_accounts.disconnect';
    case SocialAccountsDelete = 'social_accounts.delete';
    case SocialAccountsTest = 'social_accounts.test';
    case SocialAccountsRefresh = 'social_accounts.refresh';
    case SocialAccountsSync = 'social_accounts.sync';

    case SocialPagesView = 'social_pages.view';
    case SocialPagesSelect = 'social_pages.select';
    case SocialPagesSync = 'social_pages.sync';

    case MembersView = 'members.view';
    case MembersInvite = 'members.invite';
    case MembersChangeRole = 'members.change_role';
    case MembersRemove = 'members.remove';

    case AnalyticsView = 'analytics.view';
    case SettingsManage = 'settings.manage';
    case OrganizationView = 'organization.view';
    case OrganizationUpdate = 'organization.update';
    case OrganizationTransferOwnership = 'organization.transfer_ownership';
    case OrganizationDelete = 'organization.delete';
    case AuditLogsView = 'audit_logs.view';
}
