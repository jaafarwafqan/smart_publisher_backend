<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Support\Billing\BillingPeriodGrantService;
use App\Support\Billing\DefaultPlans;
use App\Support\Billing\QuotaGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prepaid-billing model (2026-08-21) — BillingPeriodGrantService is the one
 * place both a manual super-admin grant/extend and a real paid-gateway
 * renewal compute the new current_period_end. These tests exercise it
 * directly (not through the HTTP layer — see AdminSubscriptionControllerTest
 * for that) to pin down its date math precisely.
 */
class BillingPeriodGrantServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_granting_a_plan_with_no_existing_subscription_starts_the_period_from_now(): void
    {
        $organization = $this->freshOrganizationWithNoSubscription();
        $plan = $this->paidPlan();

        $subscription = app(BillingPeriodGrantService::class)->grantPlan($organization, $plan, 3, null, null, 'txn_1');

        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame('active', $subscription->status);
        $this->assertSame('txn_1', $subscription->provider_subscription_id);
        $this->assertNull($subscription->granted_by_user_id);
        $this->assertTrue($subscription->current_period_end->betweenIncluded(now()->addMonths(3)->subMinute(), now()->addMonths(3)->addMinute()));
    }

    public function test_granting_a_plan_while_a_future_period_is_still_active_extends_from_that_period_end_not_now(): void
    {
        $organization = $this->freshOrganization();
        $plan = $this->paidPlan();
        $futureEnd = now()->addDays(20);
        OrganizationSubscription::query()->updateOrCreate(['organization_id' => $organization->id], [
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => $futureEnd,
        ]);

        $subscription = app(BillingPeriodGrantService::class)->grantPlan($organization, $plan, 1, null, null, 'txn_2');

        // Never lose the 20 remaining days — the new end is futureEnd + 1
        // month, NOT now() + 1 month.
        $expected = $futureEnd->copy()->addMonth();
        $this->assertTrue($subscription->current_period_end->betweenIncluded($expected->copy()->subMinute(), $expected->copy()->addMinute()));
    }

    public function test_granting_a_plan_while_the_previous_period_already_lapsed_extends_from_now_not_the_stale_end(): void
    {
        $organization = $this->freshOrganization();
        $plan = $this->paidPlan();
        OrganizationSubscription::query()->updateOrCreate(['organization_id' => $organization->id], [
            'plan_id' => $plan->id,
            'status' => 'expired',
            'current_period_start' => now()->subMonths(2),
            'current_period_end' => now()->subMonth(),
        ]);

        $subscription = app(BillingPeriodGrantService::class)->grantPlan($organization, $plan, 1, null, null, 'txn_3');

        // Must not grant a free month on top of a long-expired period.
        $expected = now()->addMonth();
        $this->assertTrue($subscription->current_period_end->betweenIncluded($expected->copy()->subMinute(), $expected->copy()->addMinute()));
        $this->assertSame('active', $subscription->status);
    }

    public function test_extending_the_period_requires_an_existing_plan(): void
    {
        $organization = $this->freshOrganizationWithNoSubscription();
        $admin = User::factory()->create();

        $this->expectExceptionMessage('This organization has no subscription to extend yet — grant a plan first.');

        app(BillingPeriodGrantService::class)->extendPeriod($organization, 0, 10, $admin, 'testing extend with nothing to extend');
    }

    public function test_extending_records_who_granted_it_and_why(): void
    {
        $organization = $this->freshOrganization();
        $plan = $this->paidPlan();
        OrganizationSubscription::query()->updateOrCreate(['organization_id' => $organization->id], [
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_end' => now()->addDays(5),
        ]);
        $admin = User::factory()->create();

        $subscription = app(BillingPeriodGrantService::class)->extendPeriod($organization, 0, 30, $admin, 'goodwill extension');

        $this->assertSame($admin->id, $subscription->granted_by_user_id);
        $this->assertSame('goodwill extension', $subscription->granted_reason);
        $expected = now()->addDays(35);
        $this->assertTrue($subscription->current_period_end->betweenIncluded($expected->copy()->subMinute(), $expected->copy()->addMinute()));
    }

    public function test_reverting_to_free_clears_the_period_and_provider_reference(): void
    {
        $organization = $this->freshOrganization();
        $paidPlan = $this->paidPlan();
        OrganizationSubscription::query()->updateOrCreate(['organization_id' => $organization->id], [
            'plan_id' => $paidPlan->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
            'provider_subscription_id' => 'txn_active',
        ]);
        $freePlan = Plan::query()->firstOrCreate(['slug' => DefaultPlans::FREE_SLUG], DefaultPlans::free());
        $admin = User::factory()->create();

        $subscription = app(BillingPeriodGrantService::class)->revertToFree($organization, $freePlan, $admin, 'downgrade requested');

        $this->assertSame($freePlan->id, $subscription->plan_id);
        $this->assertNull($subscription->current_period_end);
        $this->assertNull($subscription->provider_subscription_id);
        $this->assertSame($admin->id, $subscription->granted_by_user_id);
    }

    public function test_granting_a_trial_defaults_to_the_fallback_plan_when_none_is_assigned_yet(): void
    {
        $organization = $this->freshOrganizationWithNoSubscription();
        $freePlan = Plan::query()->firstOrCreate(['slug' => DefaultPlans::FREE_SLUG], DefaultPlans::free());
        $admin = User::factory()->create();

        $subscription = app(BillingPeriodGrantService::class)->grantTrial($organization, 14, $admin, 'evaluation trial', $freePlan);

        $this->assertSame('trialing', $subscription->status);
        $this->assertSame($freePlan->id, $subscription->plan_id);
        $expected = now()->addDays(14);
        $this->assertTrue($subscription->current_period_end->betweenIncluded($expected->copy()->subMinute(), $expected->copy()->addMinute()));
        $this->assertTrue($subscription->trial_ends_at->betweenIncluded($expected->copy()->subMinute(), $expected->copy()->addMinute()));
    }

    private function paidPlan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Enterprise',
            'slug' => 'enterprise-'.uniqid(),
            'price_cents' => 500_000,
            'currency' => 'IQD',
            'billing_interval' => 'month',
            'limits' => array_replace(
                QuotaGates::fallbackAll(),
                array_fill_keys(QuotaGates::featureKeys(), true),
            ),
            'is_active' => true,
        ]);
    }

    private function freshOrganization(): Organization
    {
        return User::factory()->create()->currentOrganization;
    }

    private function freshOrganizationWithNoSubscription(): Organization
    {
        $organization = $this->freshOrganization();
        OrganizationSubscription::query()->where('organization_id', $organization->id)->delete();

        return $organization;
    }
}
