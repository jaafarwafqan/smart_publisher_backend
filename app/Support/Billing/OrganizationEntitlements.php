<?php

namespace App\Support\Billing;

use App\Models\OrganizationSubscription;

/**
 * CTO audit Sprint 5 (SaaS Business) — the one place application code
 * should ever ask "is this organization allowed to do X more of this."
 * Deliberately conservative: an organization with no subscription row, an
 * inactive/canceled subscription, or a plan with no limit set for a given
 * key is always treated as UNLIMITED (null), never as zero-access. Every
 * organization that exists today predates this table entirely (it starts
 * empty), so this is the only backward-compatible default — a stricter
 * default would silently lock every existing organization out the moment
 * this migration ran.
 */
class OrganizationEntitlements
{
    /**
     * @return int|null null means unlimited
     */
    public function limitFor(int $organizationId, string $key): ?int
    {
        $subscription = OrganizationSubscription::query()
            ->where('organization_id', $organizationId)
            ->with('plan')
            ->first();

        if (! $subscription || ! $subscription->isActiveOrTrialing() || ! $subscription->plan) {
            return null;
        }

        return $subscription->plan->usageLimit($key);
    }

    public function hasCapacityFor(int $organizationId, string $key, int $currentUsage): bool
    {
        $limit = $this->limitFor($organizationId, $key);

        return $limit === null || $currentUsage < $limit;
    }
}
