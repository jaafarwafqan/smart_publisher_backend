<?php

namespace Tests\Feature;

use App\Console\Commands\ExpireSubscriptionsCommand;
use App\Models\Notification;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Support\Billing\DefaultPlans;
use App\Support\Billing\QuotaGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prepaid-billing model (2026-08-21): none of FIB/ZainCash/Qi Card support
 * recurring subscriptions, so a paid period simply ends — these tests
 * cover both halves of that: isActiveOrTrialing() actually enforcing
 * current_period_end, and ExpireSubscriptionsCommand being the thing that
 * keeps status/plan_id honest once it has.
 */
class PrepaidSubscriptionExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_active_or_trialing_is_false_once_current_period_end_has_passed_even_though_status_still_says_active(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();
        $subscription = OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->subDay()],
        );

        // The bug this fixes: checking only the status string treated an
        // expired prepaid period as active forever.
        $this->assertFalse($subscription->isActiveOrTrialing());
    }

    public function test_is_active_or_trialing_is_true_with_a_null_period_end_or_a_future_one(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();

        $unbounded = OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => null],
        );
        $this->assertTrue($unbounded->isActiveOrTrialing());

        $unbounded->update(['current_period_end' => now()->addDay()]);
        $this->assertTrue($unbounded->fresh()->isActiveOrTrialing());
    }

    public function test_expiring_the_command_downgrades_a_lapsed_subscription_to_free_and_marks_it_expired(): void
    {
        $owner = User::factory()->create();
        $paidPlan = $this->paidPlan();
        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id],
            ['plan_id' => $paidPlan->id, 'status' => 'active', 'current_period_end' => now()->subHour()],
        );

        $this->artisan(ExpireSubscriptionsCommand::class)->assertExitCode(0);

        $subscription = OrganizationSubscription::query()->where('organization_id', $owner->current_organization_id)->firstOrFail();
        $this->assertSame('expired', $subscription->status);
        $freePlanId = Plan::query()->where('slug', DefaultPlans::FREE_SLUG)->value('id');
        $this->assertSame($freePlanId, $subscription->plan_id);
        $this->assertNull($subscription->current_period_end);
    }

    public function test_expiring_the_command_leaves_a_still_valid_subscription_untouched(): void
    {
        $owner = User::factory()->create();
        $paidPlan = $this->paidPlan();
        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id],
            ['plan_id' => $paidPlan->id, 'status' => 'active', 'current_period_end' => now()->addMonth()],
        );

        $this->artisan(ExpireSubscriptionsCommand::class)->assertExitCode(0);

        $subscription = OrganizationSubscription::query()->where('organization_id', $owner->current_organization_id)->firstOrFail();
        $this->assertSame('active', $subscription->status);
        $this->assertSame($paidPlan->id, $subscription->plan_id);
    }

    public function test_expiring_the_command_notifies_the_owner_exactly_at_seven_and_one_day_remaining(): void
    {
        $sevenDayOwner = User::factory()->create();
        $oneDayOwner = User::factory()->create();
        $untouchedOwner = User::factory()->create();
        $plan = $this->paidPlan();

        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $sevenDayOwner->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->addDays(7)],
        );
        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $oneDayOwner->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->addDay()],
        );
        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $untouchedOwner->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->addDays(3)],
        );

        $this->artisan(ExpireSubscriptionsCommand::class)->assertExitCode(0);

        $this->assertTrue($this->asOrganizationOf($sevenDayOwner, fn () => Notification::query()
            ->where('user_id', $sevenDayOwner->id)->where('type', 'billing.subscription_expiring')
            ->where('data->days_remaining', 7)->exists()));
        $this->assertTrue($this->asOrganizationOf($oneDayOwner, fn () => Notification::query()
            ->where('user_id', $oneDayOwner->id)->where('type', 'billing.subscription_expiring')
            ->where('data->days_remaining', 1)->exists()));
        $this->assertFalse($this->asOrganizationOf($untouchedOwner, fn () => Notification::query()
            ->where('user_id', $untouchedOwner->id)->where('type', 'billing.subscription_expiring')->exists()));
    }

    public function test_expiring_the_command_does_not_send_a_duplicate_warning_on_a_second_run_the_same_day(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();
        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->addDay()],
        );

        $this->artisan(ExpireSubscriptionsCommand::class)->assertExitCode(0);
        $this->artisan(ExpireSubscriptionsCommand::class)->assertExitCode(0);

        $count = $this->asOrganizationOf($owner, fn () => Notification::query()
            ->where('user_id', $owner->id)->where('type', 'billing.subscription_expiring')->count());
        $this->assertSame(1, $count);
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
}
