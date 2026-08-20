<?php

namespace App\Support\Billing;

/**
 * The Free plan's definition used to live only inside PlanSeeder — which
 * meant a database that skipped seeding (a fresh staging/production
 * deploy, a wiped local dev DB, a CI run against a bare migration) silently
 * ended up with NO Free plan at all. OrganizationEntitlements::limitFor()
 * fails CLOSED for a missing subscription (zero capacity — see its own
 * docblock), so on that unseeded environment every quota (team members,
 * social accounts, scheduled posts/month) would lock a brand-new
 * organization out entirely, with nothing visibly wrong until someone
 * remembered to run `db:seed`.
 *
 * Centralizing the definition here lets PersonalOrganizationProvisioner
 * guarantee the plan exists at the exact moment it's actually needed — a
 * new organization being created — instead of depending on a deployment
 * script to have seeded it first. PlanSeeder uses the same definition so
 * the two can never drift apart.
 */
class DefaultPlans
{
    public const FREE_SLUG = 'free';

    /**
     * Deliberately unpriced (price_cents/billing_interval/currency stay
     * null) — inventing a plausible-looking price here would be fabricated
     * data presented as real. The limit VALUES are a reasonable, disclosed
     * placeholder for a closed-beta free tier, not a real pricing/packaging
     * decision — revisit when the business actually defines paid tiers.
     *
     * @return array{name: string, price_cents: null, billing_interval: null, currency: null, limits: array<string, int|null>, is_active: true}
     */
    public static function free(): array
    {
        return [
            'name' => 'Free',
            'price_cents' => null,
            'billing_interval' => null,
            'currency' => null,
            'limits' => QuotaGates::fallbackLimits(),
            'is_active' => true,
        ];
    }

    /**
     * Existing organizations already over Free limits receive this explicit,
     * unpriced legacy plan during the one-time subscription backfill. Null
     * values are intentional unlimited quotas, not missing configuration.
     *
     * @return array{name: string, price_cents: null, billing_interval: null, currency: null, limits: array<string, int|null>, is_active: true}
     */
    public static function legacyGrandfathered(): array
    {
        return [
            'name' => 'Legacy grandfathered',
            'price_cents' => null,
            'billing_interval' => null,
            'currency' => null,
            'limits' => QuotaGates::unlimitedLimits(),
            'is_active' => true,
        ];
    }
}
