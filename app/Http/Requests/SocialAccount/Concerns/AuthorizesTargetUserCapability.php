<?php

namespace App\Http\Requests\SocialAccount\Concerns;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Code-quality review (2026-08-17), item A1/5.1: the FormRequest
 * counterpart to SocialAccountController::authorizeTargetUserCapability()
 * (still the source of truth for index()/show()/etc., which never had an
 * inline validate() call and so were never converted to a FormRequest).
 *
 * Must run inside FormRequest::authorize() — never rules() — because
 * Laravel resolves authorize() before validation for a FormRequest-typed
 * controller parameter, and that ordering is exactly what this fix
 * restores: the original inline-validate() controllers checked
 * $this->authorize()/authorizeTargetUserCapability() BEFORE validating the
 * body, so an unauthorized caller always saw the same 401/403/404 as
 * before, never a 422 for a body they were never entitled to submit in the
 * first place. Converting the validate() call to a FormRequest without
 * this would silently invert that order (Laravel validates a FormRequest
 * during dependency resolution, before the controller method body — and
 * therefore before any $this->authorize() call written inside it — ever
 * runs), exactly the regression
 * SocialAccountOrganizationAuthorizationTest::test_editor_can_view_but_cannot_manage_or_connect_social_accounts
 * caught during this refactor (expected 403, got 422).
 *
 * Uses abort() directly (not a bool return) so the exact status codes and
 * messages authorizeTargetUserCapability() already produces — including
 * the specific, safe 404 message SafeErrorMessagesTest asserts for a
 * target user who exists but isn't a member of the current organization —
 * are preserved unchanged rather than collapsing into FormRequest's
 * generic 403 "This action is unauthorized."
 */
trait AuthorizesTargetUserCapability
{
    protected function authorizeTargetUserCapability(OrganizationPermission $permission): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        $organizationId = app(TenantContext::class)->get();
        if (! $actor->hasOrganizationPermission($organizationId, $permission)) {
            abort(403, 'You do not have permission to manage social accounts in this organization.');
        }

        $targetUser = $this->route('user');
        if (! $targetUser instanceof User || ! $targetUser->isMemberOf($organizationId)) {
            abort(404, 'The requested user is not a member of the current organization.');
        }

        return true;
    }
}
