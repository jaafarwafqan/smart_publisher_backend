<?php

namespace App\Support\Billing;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent one-time subscription assignment for organizations that existed
 * before billing. Kept outside the migration so the grandfathering rule has
 * an executable regression test instead of being buried in migration SQL.
 */
final class OrganizationSubscriptionBackfill
{
    public function __construct(private readonly FreeTierGrandfathering $grandfathering) {}

    public function backfill(CarbonInterface $now): void
    {
        $freeDefinition = DefaultPlans::free();
        $freePlanId = $this->findOrCreatePlan(DefaultPlans::FREE_SLUG, $freeDefinition, $now);
        $legacyPlanId = null;

        DB::table('organizations')->orderBy('id')->eachById(function (object $organization) use ($freePlanId, $freeDefinition, &$legacyPlanId, $now): void {
            // Never overwrite a Stripe-managed or manually assigned plan.
            if (DB::table('organization_subscriptions')->where('organization_id', $organization->id)->exists()) {
                return;
            }

            $planId = $freePlanId;
            $usage = $this->grandfathering->usageFor((int) $organization->id);
            if ($this->grandfathering->exceedsLimits($usage, $freeDefinition['limits'])) {
                $legacyPlanId ??= $this->findOrCreatePlan(
                    'legacy-grandfathered',
                    DefaultPlans::legacyGrandfathered(),
                    $now,
                );
                $planId = $legacyPlanId;
            }

            DB::table('organization_subscriptions')->insertOrIgnore([
                'organization_id' => $organization->id,
                'plan_id' => $planId,
                'status' => 'active',
                'current_period_start' => $now,
                'current_period_end' => null,
                'trial_ends_at' => null,
                'canceled_at' => null,
                'provider_subscription_id' => null,
                'provider_customer_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * @param  array{name: string, price_cents: null, billing_interval: null, currency: null, limits: array<string, int|null>, is_active: true}  $definition
     */
    private function findOrCreatePlan(string $slug, array $definition, CarbonInterface $now): int
    {
        $existingId = DB::table('plans')->where('slug', $slug)->value('id');
        if ($existingId !== null) {
            return (int) $existingId;
        }

        return DB::table('plans')->insertGetId([
            ...$definition,
            'slug' => $slug,
            'limits' => json_encode($definition['limits'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
