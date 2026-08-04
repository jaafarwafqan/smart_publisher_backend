<?php

namespace Tests\Feature;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Support\Billing\OrganizationEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CTO audit Sprint 5 (SaaS Business) — schema-only groundwork plus one
 * concrete checkpoint (team member invites). No plan pricing exists yet
 * (a real business decision, not made here) — these tests only prove the
 * plumbing: an organization with no subscription is unlimited, and an
 * organization with an actual limit assigned gets enforced correctly.
 */
class OrganizationEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organization_with_no_subscription_row_is_treated_as_unlimited(): void
    {
        $entitlements = app(OrganizationEntitlements::class);

        $this->assertNull($entitlements->limitFor(999, 'max_team_members'));
        $this->assertTrue($entitlements->hasCapacityFor(999, 'max_team_members', 1_000_000));
    }

    public function test_a_plan_limit_is_enforced_once_an_active_subscription_exists(): void
    {
        $owner = User::factory()->create();

        $plan = Plan::query()->create([
            'name' => 'Test Plan',
            'slug' => 'test-plan-'.uniqid(),
            'limits' => ['max_team_members' => 1],
        ]);

        OrganizationSubscription::query()->create([
            'organization_id' => $owner->current_organization_id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $entitlements = app(OrganizationEntitlements::class);

        // Owner alone (1 active member) already meets the limit of 1.
        $this->assertFalse($entitlements->hasCapacityFor($owner->current_organization_id, 'max_team_members', 1));
        $this->assertTrue($entitlements->hasCapacityFor($owner->current_organization_id, 'max_team_members', 0));
    }

    public function test_invite_endpoint_rejects_a_new_member_once_the_plan_limit_is_reached(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();

        $plan = Plan::query()->create([
            'name' => 'Solo Plan',
            'slug' => 'solo-plan-'.uniqid(),
            'limits' => ['max_team_members' => 1],
        ]);

        OrganizationSubscription::query()->create([
            'organization_id' => $owner->current_organization_id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->postJson('/api/v1/organization/members', [
                'email' => $invitee->email,
                'role' => 'editor',
            ]);

        $response->assertStatus(422);
        $this->assertFalse($invitee->isMemberOf($owner->current_organization_id));
    }

    public function test_invite_endpoint_still_works_normally_when_no_subscription_exists(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->postJson('/api/v1/organization/members', [
                'email' => $invitee->email,
                'role' => 'editor',
            ]);

        $response->assertCreated();
    }
}
