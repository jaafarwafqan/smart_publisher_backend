<?php

namespace Tests\Feature;

use App\Models\BillingWebhookEvent;
use App\Models\GatewayPaymentIntent;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Support\Billing\FibBillingGateway;
use App\Support\Billing\QuotaGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FIB's webhook is documented as carrying only a payment id and a status —
 * no signature. These tests pin down the two things that stand in for
 * verification: gateway_payment_intents recovers organization/plan/months
 * from a bare payment id, and the webhook processor re-confirms the status
 * via FIB's own authenticated API rather than trusting the callback body.
 */
class FibBillingGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fib.base_url' => 'https://fib.test/protected/v1',
            'fib.login' => 'https://fib.test/auth/token',
            'fib.callback' => 'https://app.test/webhooks/fib',
            'fib.currency' => 'IQD',
            'fib.auth_accounts.default.client_id' => 'test-client',
            'fib.auth_accounts.default.secret' => 'test-secret',
            'billing.return_url' => 'https://app.test/billing',
        ]);

        Http::fake([
            'fib.test/auth/token' => Http::response(['access_token' => 'fib-token'], 200),
        ]);
    }

    public function test_creating_a_checkout_starts_a_fib_payment_and_records_a_gateway_intent(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();

        Http::fake([
            'fib.test/auth/token' => Http::response(['access_token' => 'fib-token'], 200),
            'fib.test/protected/v1/payments' => Http::response([
                'paymentId' => 'fib-payment-1',
                'readableCode' => '123456',
                'personalAppLink' => 'https://fib.test/pay/fib-payment-1',
                'validUntil' => now()->addHour()->toIso8601String(),
            ], 200),
        ]);

        $checkoutUrl = app(FibBillingGateway::class)->createCheckout($plan, $owner->currentOrganization, 2);

        $this->assertSame('https://fib.test/pay/fib-payment-1', $checkoutUrl);
        $this->assertDatabaseHas('gateway_payment_intents', [
            'gateway' => 'fib',
            'reference' => 'fib-payment-1',
            'organization_id' => $owner->current_organization_id,
            'plan_id' => $plan->id,
            'months' => 2,
            'amount' => $plan->price_cents * 2,
            'currency' => 'IQD',
            'status' => 'pending',
        ]);
    }

    public function test_creating_a_checkout_rejects_a_plan_not_priced_in_iqd(): void
    {
        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'USD Plan',
            'slug' => 'usd-plan-'.uniqid(),
            'price_cents' => 1000,
            'currency' => 'usd',
            'limits' => array_replace(QuotaGates::fallbackAll(), array_fill_keys(QuotaGates::featureKeys(), true)),
            'is_active' => true,
        ]);

        $this->expectExceptionMessage('FIB only accepts IQD — this plan is not priced in IQD.');

        app(FibBillingGateway::class)->createCheckout($plan, $owner->currentOrganization, 1);
    }

    public function test_fib_webhook_grants_the_plan_once_the_status_api_confirms_paid(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();
        $this->startFibCheckout($owner, $plan, 3, 'fib-payment-2');

        Http::fake([
            'fib.test/auth/token' => Http::response(['access_token' => 'fib-token'], 200),
            'fib.test/protected/v1/payments/fib-payment-2/status' => Http::response(['status' => 'PAID'], 200),
        ]);

        $this->postJson('/api/v1/webhooks/fib', ['paymentId' => 'fib-payment-2', 'status' => 'PAID'])
            ->assertOk()
            ->assertJsonPath('data.received', true);

        $intent = GatewayPaymentIntent::query()->where('reference', 'fib-payment-2')->firstOrFail();
        $this->assertSame('paid', $intent->status);
        $subscription = OrganizationSubscription::query()->where('organization_id', $owner->current_organization_id)->firstOrFail();
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame('active', $subscription->status);
        $this->assertSame('fib-payment-2', $subscription->provider_subscription_id);
        $this->assertNull($subscription->granted_by_user_id);
    }

    public function test_fib_webhook_rejects_an_amount_that_does_not_match_the_plans_current_price(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();
        $this->startFibCheckout($owner, $plan, 1, 'fib-payment-3');
        // Simulates a stale/tampered intent: the amount on record no longer
        // matches months * the plan's real price.
        $intent = GatewayPaymentIntent::query()->where('reference', 'fib-payment-3')->firstOrFail();
        $intent->update(['amount' => 1]);

        Http::fake([
            'fib.test/auth/token' => Http::response(['access_token' => 'fib-token'], 200),
            'fib.test/protected/v1/payments/fib-payment-3/status' => Http::response(['status' => 'PAID'], 200),
        ]);

        $this->postJson('/api/v1/webhooks/fib', ['paymentId' => 'fib-payment-3', 'status' => 'PAID'])->assertOk();

        $this->assertSame('failed', $intent->fresh()->status);
        $this->assertDatabaseMissing('organization_subscriptions', ['organization_id' => $owner->current_organization_id, 'plan_id' => $plan->id]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'billing.fib_payment_amount_mismatch', 'auditable_id' => $intent->id]);
    }

    public function test_fib_webhook_is_idempotent_for_a_repeated_delivery_of_the_same_status(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();
        $this->startFibCheckout($owner, $plan, 1, 'fib-payment-4');

        Http::fake([
            'fib.test/auth/token' => Http::response(['access_token' => 'fib-token'], 200),
            'fib.test/protected/v1/payments/fib-payment-4/status' => Http::response(['status' => 'PAID'], 200),
        ]);

        foreach ([1, 2] as $_) {
            $this->postJson('/api/v1/webhooks/fib', ['paymentId' => 'fib-payment-4', 'status' => 'PAID'])->assertOk();
        }

        $this->assertSame(1, BillingWebhookEvent::query()->where('provider', 'fib')->where('provider_event_id', 'fib-payment-4:PAID')->count());
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'billing.fib_payment_succeeded')->count());
    }

    /**
     * Runs a real createCheckout() (through Http::fake) so both this
     * application's gateway_payment_intents row AND the FIB SDK's own
     * internal fib_payments bookkeeping row exist for $reference —
     * checkPaymentStatus() (called by the webhook processor) fails if the
     * SDK's own row is missing, independent of anything this application
     * tracks itself.
     */
    private function startFibCheckout(User $owner, Plan $plan, int $months, string $reference): void
    {
        Http::fake([
            'fib.test/auth/token' => Http::response(['access_token' => 'fib-token'], 200),
            'fib.test/protected/v1/payments' => Http::response([
                'paymentId' => $reference,
                'readableCode' => '123456',
                'personalAppLink' => 'https://fib.test/pay/'.$reference,
                'validUntil' => now()->addHour()->toIso8601String(),
            ], 200),
        ]);

        app(FibBillingGateway::class)->createCheckout($plan, $owner->currentOrganization, $months);
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
