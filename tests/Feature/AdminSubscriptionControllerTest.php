<?php

namespace Tests\Feature;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Support\Billing\DefaultPlans;
use App\Support\Billing\QuotaGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prepaid-billing model (2026-08-21) — the manual super-admin side of
 * BillingPeriodGrantService. Every action requires `reason` (see each
 * FormRequest) and is written to platform_audit_logs with a before/after
 * snapshot; see AdminSubscriptionController's own docblock.
 */
class AdminSubscriptionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_super_admin_is_rejected_with_403_on_every_subscription_action(): void
    {
        $organization = User::factory()->create();
        $notAnAdmin = User::factory()->create();
        $plan = $this->paidPlan();
        Sanctum::actingAs($notAnAdmin);

        $this->postJson("/api/v1/admin/organizations/{$organization->current_organization_id}/subscription", [
            'plan_id' => $plan->id, 'months' => 1, 'reason' => 'test',
        ])->assertForbidden();

        $this->postJson("/api/v1/admin/organizations/{$organization->current_organization_id}/subscription/extend", [
            'months' => 1, 'reason' => 'test',
        ])->assertForbidden();

        $this->deleteJson("/api/v1/admin/organizations/{$organization->current_organization_id}/subscription", [
            'reason' => 'test',
        ])->assertForbidden();

        $this->postJson("/api/v1/admin/organizations/{$organization->current_organization_id}/subscription/trial", [
            'days' => 7, 'reason' => 'test',
        ])->assertForbidden();
    }

    public function test_grant_requires_a_reason(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $organization = User::factory()->create();
        $plan = $this->paidPlan();
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/v1/admin/organizations/{$organization->current_organization_id}/subscription", [
            'plan_id' => $plan->id, 'months' => 1,
        ])->assertStatus(422);
    }

    public function test_granting_a_subscription_assigns_the_plan_extends_the_period_and_is_audited(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $organization = User::factory()->create();
        $plan = $this->paidPlan();
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/v1/admin/organizations/{$organization->current_organization_id}/subscription", [
            'plan_id' => $plan->id,
            'months' => 6,
            'reason' => 'Annual conference sponsorship — comped access.',
        ])->assertCreated()
            ->assertJsonPath('data.plan_id', $plan->id)
            ->assertJsonPath('data.granted_reason', 'Annual conference sponsorship — comped access.');

        $subscription = OrganizationSubscription::query()->where('organization_id', $organization->current_organization_id)->firstOrFail();
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame($superAdmin->id, $subscription->granted_by_user_id);
        $this->assertNull($subscription->provider_subscription_id);
        $expected = now()->addMonths(6);
        $this->assertTrue($subscription->current_period_end->betweenIncluded($expected->copy()->subMinute(), $expected->copy()->addMinute()));

        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_user_id' => $superAdmin->id,
            'action' => 'organization.subscription_granted',
            'organization_id' => $organization->current_organization_id,
        ]);
        $log = PlatformAuditLog::query()->where('action', 'organization.subscription_granted')->latest('id')->firstOrFail();
        $this->assertSame($plan->id, $log->new_values['plan_id']);
    }

    public function test_extending_calculates_from_the_current_period_end_when_it_is_still_in_the_future(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $organization = User::factory()->create();
        $plan = $this->paidPlan();
        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $organization->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->addDays(10)],
        );
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/v1/admin/organizations/{$organization->current_organization_id}/subscription/extend", [
            'days' => 20,
            'reason' => 'Compensating for a platform outage.',
        ])->assertOk();

        $subscription = OrganizationSubscription::query()->where('organization_id', $organization->current_organization_id)->firstOrFail();
        // 10 remaining + 20 granted = 30, not 20 from today.
        $expected = now()->addDays(30);
        $this->assertTrue($subscription->current_period_end->betweenIncluded($expected->copy()->subMinute(), $expected->copy()->addMinute()));
    }

    public function test_extending_calculates_from_now_when_the_period_has_already_lapsed(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $organization = User::factory()->create();
        $plan = $this->paidPlan();
        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $organization->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'expired', 'current_period_end' => now()->subDays(15)],
        );
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/v1/admin/organizations/{$organization->current_organization_id}/subscription/extend", [
            'days' => 20,
            'reason' => 'Reinstating after a billing dispute was resolved.',
        ])->assertOk();

        $subscription = OrganizationSubscription::query()->where('organization_id', $organization->current_organization_id)->firstOrFail();
        // Must not grant 15 free days on top of a long-lapsed period.
        $expected = now()->addDays(20);
        $this->assertTrue($subscription->current_period_end->betweenIncluded($expected->copy()->subMinute(), $expected->copy()->addMinute()));
        $this->assertSame('active', $subscription->status);
    }

    public function test_reverting_to_free_applies_frees_limits_immediately(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $organization = User::factory()->create();
        $enterprisePlan = $this->paidPlan();
        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $organization->current_organization_id],
            ['plan_id' => $enterprisePlan->id, 'status' => 'active', 'current_period_end' => now()->addMonth()],
        );

        // Prove it was actually enabled before the revert.
        Sanctum::actingAs($organization);
        $this->withHeader('X-Organization-Id', (string) $organization->current_organization_id)
            ->getJson("/api/v1/organizations/{$organization->current_organization_id}/audit-logs")
            ->assertOk();

        Sanctum::actingAs($superAdmin);
        $this->deleteJson("/api/v1/admin/organizations/{$organization->current_organization_id}/subscription", [
            'reason' => 'Enterprise trial period ended without a paid conversion.',
        ])->assertOk();

        $subscription = OrganizationSubscription::query()->where('organization_id', $organization->current_organization_id)->firstOrFail();
        $freePlanId = Plan::query()->where('slug', DefaultPlans::FREE_SLUG)->value('id');
        $this->assertSame($freePlanId, $subscription->plan_id);
        $this->assertNull($subscription->current_period_end);

        // The very next request from the SAME organization is now denied —
        // no caching/staleness anywhere in between.
        Sanctum::actingAs($organization);
        $this->withHeader('X-Organization-Id', (string) $organization->current_organization_id)
            ->getJson("/api/v1/organizations/{$organization->current_organization_id}/audit-logs")
            ->assertStatus(403);
    }

    public function test_granting_a_trial_sets_status_trialing_and_is_audited(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $organization = User::factory()->create();
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/v1/admin/organizations/{$organization->current_organization_id}/subscription/trial", [
            'days' => 14,
            'reason' => 'Evaluating the platform before a paid commitment.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'trialing');

        $subscription = OrganizationSubscription::query()->where('organization_id', $organization->current_organization_id)->firstOrFail();
        $this->assertSame('trialing', $subscription->status);
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'organization.subscription_trial_granted',
            'organization_id' => $organization->current_organization_id,
        ]);
    }

    private function paidPlan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Enterprise',
            'slug' => 'enterprise-'.uniqid(),
            'price_cents' => 500_000,
            'currency' => 'IQD',
            'billing_interval' => 'month',
            'limits' => array_replace(QuotaGates::fallbackAll(), array_fill_keys(QuotaGates::featureKeys(), true)),
            'is_active' => true,
        ]);
    }
}
