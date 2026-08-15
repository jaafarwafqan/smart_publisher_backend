<?php

namespace App\Support\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/**
 * Owns the primary_owner_id invariant: every organization has at most one
 * primary owner, and that pointer only ever references a membership that is
 * actually role=owner, status=active, and belongs to an active user.
 *
 * primary_owner_id is deliberately NOT mass-assignable (see Organization's
 * #[Fillable] list) — it is only ever written here, via forceFill(), so
 * every write goes through the same locking and eligibility rules
 * regardless of which controller triggered it.
 *
 * Every public method must be called from inside a DB::transaction() that
 * already holds whatever locks are relevant to the caller's own mutation
 * (e.g. OrganizationMembershipController's existing guardNotLastOwner
 * pattern) — reconcile() takes its own row lock on the organization itself,
 * which is sufficient to serialize concurrent reconciliation attempts for
 * the same org, but callers remain responsible for locking the membership
 * rows they are themselves mutating.
 */
class OrganizationOwnershipService
{
    /**
     * Explicitly designate a membership as the primary owner. Used where
     * the caller already knows unambiguously who the new primary owner
     * should be (organization creation, an explicit ownership transfer) —
     * skips the "pick a fallback" search that reconcile() does.
     */
    public function assign(Organization $organization, OrganizationMembership $membership): void
    {
        if ($membership->organization_id !== $organization->id) {
            throw new \InvalidArgumentException('Membership does not belong to this organization.');
        }

        $organization->forceFill(['primary_owner_id' => $membership->id])->save();
    }

    /**
     * Re-derive the correct primary owner for an organization after any
     * membership or user-status change that could invalidate the current
     * one. Stable by design: if the existing primary_owner_id still points
     * at an eligible membership, it is left untouched — reconcile() only
     * moves the designation when it must (the current primary is gone,
     * demoted, suspended, or belongs to a deactivated user), falling back
     * to the longest-standing remaining eligible owner. If none remain,
     * primary_owner_id is set to null and the platform UI surfaces that
     * explicitly rather than silently guessing.
     */
    public function reconcile(int $organizationId): void
    {
        // Row-lock the organization so two concurrent mutations (e.g. one
        // request demoting the current primary owner while another syncs
        // memberships) can't both read a stale primary_owner_id and race
        // to set it.
        $organization = Organization::query()->lockForUpdate()->findOrFail($organizationId);

        if ($organization->primary_owner_id !== null && $this->isEligible($organizationId, $organization->primary_owner_id)) {
            return;
        }

        $fallback = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('role', OrganizationRole::Owner)
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        $organization->forceFill(['primary_owner_id' => $fallback?->id])->save();
    }

    private function isEligible(int $organizationId, int $membershipId): bool
    {
        return OrganizationMembership::query()
            ->whereKey($membershipId)
            ->where('organization_id', $organizationId)
            ->where('role', OrganizationRole::Owner)
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->exists();
    }
}
