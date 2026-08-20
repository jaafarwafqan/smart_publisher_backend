<?php

namespace Tests\Feature;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Support\Billing\OrganizationEntitlements;
use App\Support\Billing\QuotaGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CTO audit Sprint 5 (SaaS Business) — schema-only groundwork plus one
 * concrete checkpoint (team member invites). No plan pricing exists yet
 * (a real business decision, not made here) — these tests only prove the
 * plumbing: an organization with no subscription fails closed, and an
 * organization with an actual limit assigned gets enforced correctly.
 */
class OrganizationEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organization_with_no_subscription_row_has_zero_capacity(): void
    {
        $entitlements = app(OrganizationEntitlements::class);

        $this->assertSame(0, $entitlements->limitFor(999, 'max_team_members'));
        $this->assertFalse($entitlements->hasCapacityFor(999, 'max_team_members', 0));
    }

    public function test_a_plan_limit_is_enforced_once_an_active_subscription_exists(): void
    {
        $owner = User::factory()->create();

        $plan = Plan::query()->create([
            'name' => 'Test Plan',
            'slug' => 'test-plan-'.uniqid(),
            'limits' => array_replace(QuotaGates::fallbackLimits(), ['max_team_members' => 1]),
        ]);

        // PersonalOrganizationProvisioner now guarantees a Free-plan
        // subscription already exists at this point — replace it rather
        // than inserting a second row (organization_subscriptions.
        // organization_id is unique).
        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'active'],
        );

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
            'limits' => array_replace(QuotaGates::fallbackLimits(), ['max_team_members' => 1]),
        ]);

        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'active'],
        );

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->postJson('/api/v1/organization/members', [
                'email' => $invitee->email,
                'role' => 'editor',
            ]);

        $response->assertStatus(422);
        $this->assertFalse($invitee->isMemberOf($owner->current_organization_id));
    }

    public function test_invite_endpoint_fails_closed_when_no_subscription_exists(): void
    {
        $owner = User::factory()->create();
        OrganizationSubscription::query()->where('organization_id', $owner->current_organization_id)->delete();
        $invitee = User::factory()->create();

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->postJson('/api/v1/organization/members', [
                'email' => $invitee->email,
                'role' => 'editor',
            ]);

        $response->assertStatus(422);
    }

    public function test_a_legacy_active_plan_missing_a_known_key_receives_its_declared_fallback(): void
    {
        $owner = User::factory()->create();
        $now = now();
        $legacyPlanId = DB::table('plans')->insertGetId([
            'name' => 'Legacy Professional',
            'slug' => 'legacy-professional-'.uniqid(),
            'limits' => json_encode(['max_team_members' => 50, 'max_social_accounts' => 50], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id],
            ['plan_id' => $legacyPlanId, 'status' => 'active'],
        );

        $entitlements = app(OrganizationEntitlements::class);

        $this->assertSame(
            QuotaGates::fallbackFor(QuotaGates::SCHEDULED_POSTS_PER_MONTH),
            $entitlements->limitFor($owner->current_organization_id, QuotaGates::SCHEDULED_POSTS_PER_MONTH),
        );
        $this->assertTrue($entitlements->hasCapacityFor(
            $owner->current_organization_id,
            QuotaGates::SCHEDULED_POSTS_PER_MONTH,
            0,
        ));
    }

    public function test_an_active_plan_cannot_be_saved_without_every_known_quota_gate(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('max_scheduled_posts_per_month');

        Plan::query()->create([
            'name' => 'Incomplete Professional',
            'slug' => 'incomplete-professional-'.uniqid(),
            'limits' => ['max_team_members' => 50, 'max_social_accounts' => 50],
            'is_active' => true,
        ]);
    }

    public function test_every_active_plan_in_the_migrated_database_declares_all_known_quota_gates(): void
    {
        $missingByPlan = Plan::query()
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (Plan $plan): array => [$plan->slug => QuotaGates::missingFrom($plan->limits)])
            ->filter(fn (array $missing): bool => $missing !== [])
            ->all();

        $this->assertSame([], $missingByPlan, 'Every active plan must explicitly declare each known quota gate.');
    }
}
