<?php

namespace App\Support\Tenancy;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\User;
use App\Support\Billing\DefaultPlans;
use App\Support\Organizations\OrganizationOwnershipService;
use Illuminate\Support\Facades\DB;
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
        // Every caller (RegisterController's docblock included) already
        // describes this as atomic, but nothing here actually enforced
        // that: four sequential, unguarded writes. OrganizationEntitlements
        // now fails CLOSED (zero capacity) for a missing/incomplete
        // subscription — not the "unlimited" fallback older comments in
        // this codebase described — so a partial failure here (e.g. the
        // subscription insert throwing after the organization and
        // membership already committed) would leave a brand-new,
        // already-authenticated customer completely locked out instead of
        // merely un-metered. Wrapping in a transaction makes the
        // documented "atomically establishes" claim actually true: either
        // the whole workspace exists, or none of it does.
        return DB::transaction(function () use ($user): Organization {
            $organization = Organization::query()->create([
                'name' => $user->name."'s Organization",
                'slug' => Str::slug($user->name.'-'.$user->id.'-'.Str::random(6)),
            ]);

            $ownerMembership = OrganizationMembership::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => OrganizationRole::Owner,
                'status' => 'active',
            ]);

            // This is the highest-volume org-creation path in the app (every
            // self-registered user), so it's the one most likely to leave
            // primary_owner_id null if skipped — set it explicitly rather than
            // relying on a later reconcile() call to catch it.
            (new OrganizationOwnershipService)->assign($organization, $ownerMembership);

            $user->forceFill(['current_organization_id' => $organization->id])->saveQuietly();

            // Guaranteed to exist — auto-created here (via DefaultPlans, the
            // same definition PlanSeeder uses) if a deployment's seeders were
            // never run. Every new organization now gets a real,
            // quota-enforcing subscription from the moment it's created,
            // instead of ever being able to reach OrganizationEntitlements'
            // fail-closed "no subscription row" path (zero capacity) by
            // accident on a fresh, unseeded database — that race is exactly
            // the gap this closes.
            $freePlan = Plan::query()->firstOrCreate(
                ['slug' => DefaultPlans::FREE_SLUG],
                DefaultPlans::free(),
            );

            $organization->subscription()->create([
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'current_period_start' => now(),
            ]);

            return $organization;
        });
    }
}
