<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the primary_owner_id invariant added by the 2026-08-12 fix for the
 * platform admin panel reporting "no active owner" for organizations that
 * actually had one. See OrganizationOwnershipService for the invariant
 * itself; this file exercises every code path that must keep it correct.
 */
class PrimaryOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_organization_provisioning_sets_primary_owner(): void
    {
        // Every self-registered user's org goes through
        // PersonalOrganizationProvisioner — the highest-volume org-creation
        // path in the app, and the one the fix could least afford to miss.
        $user = User::factory()->create();

        $organization = Organization::query()->findOrFail($user->current_organization_id);
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame($membership->id, $organization->primary_owner_id);
    }

    public function test_platform_admin_organization_creation_sets_primary_owner(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $owner = User::factory()->create();
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/v1/admin/organizations', [
            'name' => 'Primary Owner Test Org',
            'owner_user_id' => $owner->id,
        ])->assertCreated();

        $organizationId = (int) $response->json('data.id');
        $organization = Organization::query()->findOrFail($organizationId);
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $this->assertSame($membership->id, $organization->primary_owner_id);
    }

    public function test_platform_panel_surfaces_a_drifted_organization_as_missing_a_primary_owner(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $owner = User::factory()->create();
        $organization = $this->organizationWithOwnerBypassingService($owner);
        Sanctum::actingAs($superAdmin);

        // This organization has a real, active Owner membership — created
        // by writing straight to the DB the way pre-fix code (or a manual
        // data fix) could — but primary_owner_id was never set. This is the
        // exact drift the 2026-08-12 audit found on Staging.
        $this->getJson("/api/v1/admin/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.organization.primary_owner', null)
            ->assertJsonPath('data.organization.primary_owner_missing', true);
    }

    public function test_reconcile_endpoint_fixes_a_drifted_organization(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $owner = User::factory()->create();
        $organization = $this->organizationWithOwnerBypassingService($owner);
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/v1/admin/organizations/{$organization->id}/reconcile-primary-owner")
            ->assertOk()
            ->assertJsonPath('data.primary_owner.id', $owner->id)
            ->assertJsonPath('data.primary_owner_missing', false);

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();
        $this->assertSame($membership->id, $organization->fresh()->primary_owner_id);
    }

    public function test_reconcile_endpoint_reports_when_no_eligible_owner_exists(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $organization = Organization::query()->create([
            'name' => 'Truly Ownerless Org',
            'slug' => 'truly-ownerless-org',
            'status' => 'active',
        ]);
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/v1/admin/organizations/{$organization->id}/reconcile-primary-owner")
            ->assertOk()
            ->assertJsonPath('data.primary_owner', null)
            ->assertJsonPath('data.primary_owner_missing', true);
    }

    public function test_demoting_the_primary_owner_falls_back_to_the_remaining_owner(): void
    {
        $owner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $admin = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $owner->current_organization_id,
            'user_id' => $secondOwner->id,
            'role' => OrganizationRole::Owner,
            'status' => 'active',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $owner->current_organization_id,
            'user_id' => $admin->id,
            'role' => OrganizationRole::Admin,
            'status' => 'active',
        ]);

        // An actor other than $owner must perform this — changing your own
        // role is blocked outright regardless of permission.
        Sanctum::actingAs($admin);
        $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->putJson('/api/v1/organization/members/'.$owner->id, ['role' => 'admin'])
            ->assertOk();

        $secondMembership = OrganizationMembership::query()
            ->where('organization_id', $owner->current_organization_id)
            ->where('user_id', $secondOwner->id)
            ->firstOrFail();

        $organization = Organization::query()->findOrFail($owner->current_organization_id);
        $this->assertSame($secondMembership->id, $organization->primary_owner_id);
    }

    public function test_last_owner_guard_does_not_count_a_deactivated_users_membership(): void
    {
        $owner = User::factory()->create();
        $deactivatedOwner = User::factory()->create(['is_active' => false]);
        $admin = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $owner->current_organization_id,
            'user_id' => $deactivatedOwner->id,
            'role' => OrganizationRole::Owner,
            'status' => 'active',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $owner->current_organization_id,
            'user_id' => $admin->id,
            'role' => OrganizationRole::Admin,
            'status' => 'active',
        ]);

        // Two role=owner/status=active memberships exist, but only one
        // belongs to an active user — $owner is the last REAL owner and
        // must still be protected, which requires guardNotLastOwner to
        // check user.is_active the same way PlatformAdministrationGuard
        // does (this is the inconsistency the fix closes).
        Sanctum::actingAs($admin);
        $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->putJson('/api/v1/organization/members/'.$owner->id, ['role' => 'admin'])
            ->assertStatus(422);

        $this->assertSame('owner', $owner->fresh()->roleIn($owner->current_organization_id)->value);
    }

    public function test_deactivating_the_primary_owner_falls_back_to_the_remaining_owner(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $owner = User::factory()->create();
        $secondOwner = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $owner->current_organization_id,
            'user_id' => $secondOwner->id,
            'role' => OrganizationRole::Owner,
            'status' => 'active',
        ]);
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/v1/admin/users/{$owner->id}/status", ['is_active' => false])
            ->assertOk();

        $secondMembership = OrganizationMembership::query()
            ->where('organization_id', $owner->current_organization_id)
            ->where('user_id', $secondOwner->id)
            ->firstOrFail();
        $organization = Organization::query()->findOrFail($owner->current_organization_id);
        $this->assertSame($secondMembership->id, $organization->primary_owner_id);
    }

    /**
     * Simulates pre-fix (or manually written) data: a real, active Owner
     * membership that primary_owner_id was never pointed at — bypasses
     * OrganizationOwnershipService entirely, unlike every real write path.
     */
    private function organizationWithOwnerBypassingService(User $owner): Organization
    {
        $organization = Organization::query()->create([
            'name' => 'Organization '.$owner->id,
            'slug' => 'organization-'.$owner->id,
            'status' => 'active',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'role' => OrganizationRole::Owner,
            'status' => 'active',
        ]);

        return $organization;
    }
}
