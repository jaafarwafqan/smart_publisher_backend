<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Support\Billing\DefaultPlans;
use Illuminate\Database\Seeder;

/**
 * Sprint 4 (Commercial SaaS): the one plan every newly-created organization
 * is actually assigned to (see PersonalOrganizationProvisioner), activating
 * the OrganizationEntitlements quota mechanism. Running this explicitly
 * (rather than relying only on PersonalOrganizationProvisioner's own
 * runtime firstOrCreate() fallback) is still worth doing as part of normal
 * seeding — it makes the Free plan's existence predictable/inspectable
 * ahead of any organization being created, rather than only appearing
 * lazily on first use.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::query()->updateOrCreate(
            ['slug' => DefaultPlans::FREE_SLUG],
            DefaultPlans::free(),
        );
    }
}
