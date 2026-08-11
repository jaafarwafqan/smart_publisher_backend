<?php

namespace Database\Seeders;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\User;
use App\Support\Billing\DefaultPlans;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Staging-only realistic SaaS test fixtures — deliberately NOT called from
 * DatabaseSeeder (unlike AdminUserSeeder/PlanSeeder/BranchSeeder/
 * RolesAndPermissionsSeeder, which are safe and expected in every real
 * environment including production). This seeder creates disposable fake
 * accounts sharing one known password, so it must only ever run where
 * docker/render/start.sh explicitly guards it on APP_ENV=staging.
 *
 * Fixtures:
 * - "Smart Publisher Demo": one organization, four accounts spanning every
 *   OrganizationRole (owner/admin/editor/viewer), to exercise permission
 *   gating and UI-level isolation between roles.
 * - "Smart Publisher Demo — Isolation Org": a second organization with its
 *   own owner account, to exercise cross-organization access denial (this
 *   account must never see the first organization's data).
 *
 * All updateOrCreate/firstOrCreate-based, so re-running (every boot, same
 * as the other seeders) is safe and just converges to the same state.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('DEMO_ACCOUNTS_PASSWORD');
        if (! $password) {
            throw new RuntimeException('DEMO_ACCOUNTS_PASSWORD must be defined in the environment.');
        }

        $freePlan = Plan::query()->firstOrCreate(
            ['slug' => DefaultPlans::FREE_SLUG],
            DefaultPlans::free(),
        );

        $demoOrg = $this->organization('Smart Publisher Demo', $freePlan);
        $this->member($demoOrg, OrganizationRole::Owner, 'demo-owner@smartpublisher.test', 'Demo Owner', $password);
        $this->member($demoOrg, OrganizationRole::Admin, 'demo-admin@smartpublisher.test', 'Demo Admin', $password);
        $this->member($demoOrg, OrganizationRole::Editor, 'demo-editor@smartpublisher.test', 'Demo Editor', $password);
        $this->member($demoOrg, OrganizationRole::Viewer, 'demo-viewer@smartpublisher.test', 'Demo Viewer', $password);

        $isolationOrg = $this->organization('Smart Publisher Demo — Isolation Org', $freePlan);
        $this->member(
            $isolationOrg,
            OrganizationRole::Owner,
            'demo-isolation@smartpublisher.test',
            'Demo Isolation Owner',
            $password,
        );
    }

    private function organization(string $name, Plan $freePlan): Organization
    {
        $organization = Organization::query()->firstOrCreate(
            ['name' => $name],
            ['slug' => $this->uniqueSlug($name), 'status' => 'active'],
        );

        // Mirrors AdminOrganizationController::store()'s reasoning: an
        // organization created outside PersonalOrganizationProvisioner has
        // no subscription row unless something explicitly creates one, and
        // OrganizationEntitlements::hasCapacityFor() treats that as
        // unlimited — silently bypassing every quota this fixture is
        // supposed to exercise realistically.
        if (! $organization->subscription()->exists()) {
            $organization->subscription()->create([
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'current_period_start' => now(),
            ]);
        }

        return $organization;
    }

    private function member(
        Organization $organization,
        OrganizationRole $role,
        string $email,
        string $name,
        string $password,
    ): void {
        // Same guard AdminUserController::store()/AdminOrganizationController::store()
        // use: these accounts belong to an org this method assigns
        // explicitly, so User::booted()'s auto personal-organization
        // provisioning must not also give each one a redundant empty org.
        $user = User::withoutPersonalOrganizationProvisioning(fn (): User => User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => 'user',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        ));

        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $user->id],
            ['role' => $role, 'status' => 'active'],
        );
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $suffix = 2;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
