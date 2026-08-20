<?php

namespace App\Support\Billing;

use App\Models\OrganizationSubscription;

/**
 * CTO audit Sprint 5 (SaaS Business) — the one place application code
 * should ever ask "is this organization allowed to do X more of this."
 * A subscription is the authority for a tenant's product capacity. Missing,
 * inactive, malformed, or incomplete subscription data therefore fails
 * closed (zero capacity), never open. The accompanying data migration gives
 * every existing organization a Free subscription before this policy takes
 * effect, so legacy tenants remain usable without preserving an unlimited
 * bypass forever.
 */
class OrganizationEntitlements
{
    /**
     * @return int|null null means explicitly unlimited for an active plan
     */
    public function limitFor(int $organizationId, string $key): ?int
    {
        $subscription = OrganizationSubscription::query()
            ->where('organization_id', $organizationId)
            ->with('plan')
            ->first();

        if (! $subscription || ! $subscription->isActiveOrTrialing() || ! $subscription->plan) {
            return 0;
        }

        $limits = $subscription->plan->limits ?? [];
        if (array_key_exists($key, $limits)) {
            return $subscription->plan->usageLimit($key);
        }

        // A legacy paid plan can predate a newly-added gate. It must not be
        // locked to zero capacity in production while the configuration test
        // reports the omission in CI. The fallback is explicit and bounded
        // in QuotaGates, never an implicit unlimited allowance.
        return QuotaGates::fallbackFor($key);
    }

    public function hasCapacityFor(int $organizationId, string $key, int $currentUsage): bool
    {
        $limit = $this->limitFor($organizationId, $key);

        return $limit === null || $currentUsage < $limit;
    }
}
