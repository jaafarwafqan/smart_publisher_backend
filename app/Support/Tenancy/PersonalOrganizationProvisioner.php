<?php

namespace App\Support\Tenancy;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Sprint 4 (Commercial SaaS): the one place a fresh personal organization is
 * created for a user — was previously duplicated between User::booted()
 * (fires on every User::create() outside an active TenantContext) and
 * AdminUserSeeder (which must provision explicitly since DatabaseSeeder
 * runs under WithoutModelEvents, muting the model event entirely). Both
 * call sites now share this so the newly-added default-plan assignment
 * below can't drift out of sync between them the way the two blocks
 * already had before this change.
 */
class PersonalOrganizationProvisioner
{
    public static function provision(User $user): Organization
    {
        $organization = Organization::query()->create([
            'name' => $user->name."'s Organization",
            'slug' => Str::slug($user->name.'-'.$user->id.'-'.Str::random(6)),
        ]);

        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => OrganizationRole::Owner,
            'status' => 'active',
        ]);

        $user->forceFill(['current_organization_id' => $organization->id])->saveQuietly();

        // A missing 'free' plan (e.g. a test environment that never ran
        // PlanSeeder) leaves the organization with no subscription row at
        // all — OrganizationEntitlements already treats that as unlimited,
        // the same backward-compatible default every pre-existing
        // organization gets, so this deliberately does not throw or
        // require the plan to exist.
        $freePlan = Plan::query()->where('slug', 'free')->first();
        if ($freePlan !== null) {
            $organization->subscription()->create([
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'current_period_start' => now(),
            ]);
        }

        return $organization;
    }
}
