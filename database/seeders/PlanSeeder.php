<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Sprint 4 (Commercial SaaS): the one plan every newly-created organization
 * is actually assigned to (see User::booted()), activating the
 * OrganizationEntitlements quota mechanism that previously had no real plan
 * to check against. Deliberately unpriced (price_cents/billing_interval/
 * currency stay null) — per the plans migration's own documented reasoning,
 * inventing a plausible-looking price here would be the same "fabricated
 * data presented as real" mistake this project's audits have repeatedly
 * flagged elsewhere. The limit VALUES below are a reasonable, disclosed
 * placeholder for a closed-beta free tier, not a real pricing/packaging
 * decision — revisit when the business actually defines paid tiers.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::query()->updateOrCreate(
            ['slug' => 'free'],
            [
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
            ]
        );
    }
}
