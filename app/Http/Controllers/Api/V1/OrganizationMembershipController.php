<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Billing\OrganizationEntitlements;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manages membership in the CURRENT active organization (resolved from
 * TenantContext, same as every other tenant-scoped resource) — there is no
 * {organization} route parameter here, so a caller can never manage
 * membership in an organization they haven't made active via
 * ResolveTenantContext's usual verified-membership rules.
 *
 * Safety rules (Sprint 2, decided without needing to ask — standard,
 * low-ambiguity conventions):
 *  - Only owner/admin hold members.invite/change_role/remove (role matrix).
 *  - Nobody can change their own role — self-promotion (or self-demotion)
 *    through this endpoint is blocked outright, regardless of permission.
 *  - Only an existing owner can assign the owner role or change/remove an
 *    existing owner's membership — an admin granting "owner" would be
 *    granting a permission (organization.transfer_ownership) they don't
 *    themselves hold.
 *  - The last remaining owner of an organization can never be demoted or
 *    removed — ownership must be transferred to someone else first.
 */
class OrganizationMembershipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, OrganizationPermission::MembersView);

        $organizationId = app(TenantContext::class)->get();

        $members = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->with('user:id,name,email')
            ->get();

        return response()->json([
            'data' => $members->map(fn (OrganizationMembership $membership) => [
                'user_id' => $membership->user_id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role' => $membership->role->value,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, OrganizationPermission::MembersInvite);

        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ]);

        $role = OrganizationRole::from($validated['role']);
        $this->guardOwnerRoleAssignment($request, $role);

        $organizationId = app(TenantContext::class)->get();
        $invitee = User::query()->where('email', $validated['email'])->firstOrFail();

        if ($invitee->isMemberOf($organizationId)) {
            throw ValidationException::withMessages([
                'email' => ['This user is already a member of the organization.'],
            ]);
        }

        // Sprint 5 (SaaS Business) proof-of-concept checkpoint. No
        // organization has a subscription row today (the table starts
        // empty), so OrganizationEntitlements::hasCapacityFor() returns
        // true for every existing organization — this is a no-op until a
        // real plan with a max_team_members limit is actually assigned to
        // an organization.
        $currentMemberCount = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->count();

        if (! app(OrganizationEntitlements::class)->hasCapacityFor($organizationId, 'max_team_members', $currentMemberCount)) {
            throw ValidationException::withMessages([
                'email' => ['Your organization has reached its team member limit for the current plan.'],
            ]);
        }

        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $invitee->id,
            'role' => $role,
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Member added.',
            'data' => [
                'user_id' => $invitee->id,
                'name' => $invitee->name,
                'email' => $invitee->email,
                'role' => $membership->role->value,
            ],
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeCapability($request, OrganizationPermission::MembersChangeRole);

        $organizationId = app(TenantContext::class)->get();

        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'You cannot change your own role.',
            ], 403);
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'This user is not a member of the organization.'], 404);
        }

        $validated = $request->validate([
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ]);

        $newRole = OrganizationRole::from($validated['role']);
        $this->guardOwnerRoleAssignment($request, $newRole);

        if ($membership->role === OrganizationRole::Owner && $newRole !== OrganizationRole::Owner) {
            // Row-lock the org's owner memberships and re-check + mutate
            // inside the same transaction — without this, two concurrent
            // demote/remove requests can both read ownerCount > 1, both
            // pass the guard, and both commit, leaving zero owners.
            DB::transaction(function () use ($organizationId, $membership, $newRole): void {
                $this->guardNotLastOwner($organizationId, $membership);
                $membership->update(['role' => $newRole]);
            });
        } else {
            $membership->update(['role' => $newRole]);
        }

        return response()->json([
            'message' => 'Member role updated.',
            'data' => [
                'user_id' => $user->id,
                'role' => $membership->fresh()->role->value,
            ],
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeCapability($request, OrganizationPermission::MembersRemove);

        $organizationId = app(TenantContext::class)->get();

        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'You cannot remove yourself from the organization.',
            ], 403);
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'This user is not a member of the organization.'], 404);
        }

        if ($membership->role === OrganizationRole::Owner) {
            DB::transaction(function () use ($organizationId, $membership): void {
                $this->guardNotLastOwner($organizationId, $membership);
                $membership->delete();
            });
        } else {
            $membership->delete();
        }

        return response()->json(['message' => 'Member removed.']);
    }

    private function authorizeCapability(Request $request, OrganizationPermission $permission): void
    {
        $organizationId = app(TenantContext::class)->get();

        if (! $request->user()->hasOrganizationPermission($organizationId, $permission)) {
            abort(403, 'You do not have permission to manage members in this organization.');
        }
    }

    /**
     * Only an existing owner may assign the owner role to anyone (including
     * themselves transferring it away) — an admin doing so would be
     * exercising organization.transfer_ownership, which admin does not
     * hold.
     */
    private function guardOwnerRoleAssignment(Request $request, OrganizationRole $targetRole): void
    {
        if ($targetRole !== OrganizationRole::Owner) {
            return;
        }

        $organizationId = app(TenantContext::class)->get();
        if (! $request->user()->hasOrganizationPermission($organizationId, OrganizationPermission::OrganizationTransferOwnership)) {
            abort(403, 'Only an existing owner may grant the owner role.');
        }
    }

    /**
     * Must be called from inside a DB::transaction() so the lock below is
     * actually held until the caller's update()/delete() commits — locking
     * here alone and mutating after the transaction closure returns would
     * defeat the point (the lock releases the moment this method returns).
     */
    private function guardNotLastOwner(int $organizationId, OrganizationMembership $membership): void
    {
        $ownerCount = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('role', OrganizationRole::Owner)
            ->where('status', 'active')
            ->lockForUpdate()
            ->count();

        if ($ownerCount <= 1) {
            abort(422, 'This is the last owner of the organization — transfer ownership to someone else first.');
        }
    }
}
