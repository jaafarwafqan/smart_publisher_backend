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
    case PostsApprove = 'posts.approve';
    case PostsPublish = 'posts.publish';
    case PostsDeleteOwn = 'posts.delete_own';
    case PostsDeleteAll = 'posts.delete_all';

    case SocialAccountsView = 'social_accounts.view';
    case SocialAccountsConnect = 'social_accounts.connect';
    case SocialAccountsDisconnect = 'social_accounts.disconnect';
    case SocialPagesManage = 'social_pages.manage';

    case MembersView = 'members.view';
    case MembersInvite = 'members.invite';
    case MembersChangeRole = 'members.change_role';
    case MembersRemove = 'members.remove';

    case AnalyticsView = 'analytics.view';
    case SettingsManage = 'settings.manage';
    case OrganizationTransferOwnership = 'organization.transfer_ownership';
    case OrganizationDelete = 'organization.delete';
}
