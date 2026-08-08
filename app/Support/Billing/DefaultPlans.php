<?php

namespace App\Support\Billing;

/**
 * The Free plan's definition used to live only inside PlanSeeder — which
 * meant a database that skipped seeding (a fresh staging/production
 * deploy, a wiped local dev DB, a CI run against a bare migration) silently
 * ended up with NO Free plan at all. OrganizationEntitlements::
 * hasCapacityFor() treats a missing subscription as unlimited by design
 * (see its own docblock — the deliberate backward-compatible default for
 * organizations that predate billing entirely), so every quota (team
 * members, social accounts, scheduled posts/month) quietly stopped being
 * enforced on that environment, with nothing visibly wrong until someone
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
     * @return array<string, mixed>
     */
    public static function free(): array
    {
        return [
            'name' => 'Free',
            'price_cents' => null,
            'billing_interval' => null,
            'currency' => null,
            'limits' => [
                'max_team_members' => 5,
                'max_social_accounts' => 3,
                'max_scheduled_posts_per_month' => 30,
            ],
            'is_active' => true,
        ];
    }
}
