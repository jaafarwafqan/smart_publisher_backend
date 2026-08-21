<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\IndexAuditLogsRequest;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Support\Billing\OrganizationEntitlements;
use App\Support\Billing\QuotaGates;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets a multi-membership user see which organizations they belong to and
 * switch their active one. Deliberately does NOT let the client set
 * organization_id directly on anything else — this is the one sanctioned
 * place a request can influence TenantContext, and only after verifying a
 * real, active membership exists (see OrganizationMembership + User::isMemberOf()).
 */
class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $memberships = $user->memberships()
            ->with('organization:id,name,slug')
            ->where('status', 'active')
            ->get();

        return response()->json([
            'data' => $memberships->map(fn ($membership) => [
                'id' => $membership->organization->id,
                'name' => $membership->organization->name,
                'slug' => $membership->organization->slug,
                'role' => $membership->role->value,
                'is_current' => $membership->organization_id === $user->current_organization_id,
                // Sprint E (role/permission remediation, 2026-08-09): the
                // single source of truth for what a role grants is
                // OrganizationRole::permissions() — Flutter previously
                // re-derived this itself from the role NAME via a
                // hand-maintained, hard-to-keep-in-sync map
                // (OrganizationRolePermissions, now deleted). Sending the
                // resolved permission list directly means a future change
                // to the role matrix here takes effect in the client with
                // zero Flutter changes required.
                'permissions' => array_map(
                    fn (OrganizationPermission $permission): string => $permission->value,
                    $membership->role->permissions(),
                ),
            ])->values(),
        ]);
    }

    /**
     * The active organization's own profile (name/slug/status) — distinct
     * from index()'s list of memberships. Gated by the dedicated
     * organization.view/organization.update permissions (Sprint B of the
     * role/permission remediation) rather than settings.manage, since
     * viewing/renaming the organization itself is a narrower capability
     * than the broader app-settings surface SettingsController guards.
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = app(TenantContext::class)->get();

        if (! $user->hasOrganizationPermission($organizationId, OrganizationPermission::OrganizationView)) {
            abort(403, 'You do not have permission to view this organization.');
        }

        $organization = Organization::query()->findOrFail($organizationId);

        return response()->json(['data' => $this->transform($organization)]);
    }

    public function updateCurrent(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = app(TenantContext::class)->get();

        if (! $user->hasOrganizationPermission($organizationId, OrganizationPermission::OrganizationUpdate)) {
            abort(403, 'You do not have permission to update this organization.');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        $organization = Organization::query()->findOrFail($organizationId);
        $organization->update($validated);

        return response()->json([
            'message' => 'Organization updated.',
            'data' => $this->transform($organization->fresh()),
        ]);
    }

    private function transform(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status,
        ];
    }

    /**
     * Sprint G (role/permission remediation): explicit {organization} route
     * model binding, deliberately NOT the ambient TenantContext pattern
     * every other method in this controller family uses — an owner may
     * reasonably want to review a different organization's trail (one
     * they're a member of but haven't made "active") without switching.
     * Organization itself carries no OrganizationScope (it IS the tenant,
     * not a tenant-owned row), so a non-existent id still 404s via normal
     * route-model-binding, but "exists, you're just not entitled to it" is
     * a 403 here — never silently narrowed to an empty list, which would
     * look identical to "this organization simply has no audit history".
     */
    public function auditLogs(Organization $organization, IndexAuditLogsRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasOrganizationPermission($organization->id, OrganizationPermission::AuditLogsView)) {
            abort(403, 'You do not have permission to view this organization\'s audit log.');
        }
        app(OrganizationEntitlements::class)->assertFeatureEnabled(
            (int) $organization->id,
            QuotaGates::FEATURE_AUDIT_LOG,
            'The audit log is not available on your organization\'s current plan.',
        );

        $validated = $request->validated();
        $query = PlatformAuditLog::query()
            ->where('organization_id', $organization->id)
            ->with('actor:id,name,email');

        if ($userId = $validated['user_id'] ?? null) {
            $query->where('actor_user_id', $userId);
        }
        if ($action = $validated['action'] ?? null) {
            $query->where('action', $action);
        }
        if ($dateFrom = $validated['date_from'] ?? null) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $validated['date_to'] ?? null) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $entries = $query->latest('created_at')->paginate($validated['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => $entries->getCollection()->map(fn (PlatformAuditLog $entry): array => [
                'id' => $entry->id,
                'actor' => $entry->actor ? ['id' => $entry->actor->id, 'name' => $entry->actor->name, 'email' => $entry->actor->email] : null,
                'action' => $entry->action,
                'auditable_type' => class_basename($entry->auditable_type),
                'auditable_id' => $entry->auditable_id,
                'old_values' => $entry->old_values,
                'new_values' => $entry->new_values,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    public function switch(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();

        if (! $user->isMemberOf($organization->id)) {
            return response()->json([
                'message' => 'You are not a member of this organization.',
            ], 403);
        }

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        $role = $user->roleIn($organization->id);

        return response()->json([
            'message' => 'Active organization switched.',
            'data' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => $role->value,
                'permissions' => array_map(
                    fn (OrganizationPermission $permission): string => $permission->value,
                    $role->permissions(),
                ),
            ],
        ]);
    }
}
