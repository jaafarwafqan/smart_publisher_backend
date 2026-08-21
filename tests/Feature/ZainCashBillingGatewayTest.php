<?php

namespace Tests\Feature;

use App\Models\BillingWebhookEvent;
use App\Models\GatewayPaymentIntent;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Support\Billing\QuotaGates;
use App\Support\Billing\ZainCashBillingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ZainCash's callback (both the browser return and the server-to-server
 * webhook) carries a JWT signed with the merchant's own client secret —
 * these tests pin down signature verification (ZainCashJwt), the
 * webhook-is-authoritative/return-is-read-only split, and the same
 * amount-must-match-the-database-plan-price rule FIB's integration enforces.
 */
class ZainCashBillingGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-zaincash-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.zaincash.base_url' => 'https://zaincash.test',
            'billing.zaincash.client_id' => 'test-client',
            'billing.zaincash.client_secret' => self::SECRET,
            'billing.zaincash.msisdn' => '9647700000000',
            'billing.zaincash.success_url' => 'https://app.test/billing/zaincash/return',
            'billing.zaincash.failure_url' => 'https://app.test/billing/zaincash/failed',
        ]);
    }

    public function test_creating_a_checkout_gets_an_oauth_token_and_starts_a_transaction(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();

        Http::fake([
            'zaincash.test/oauth2/token' => Http::response(['access_token' => 'zc-token'], 200),
            'zaincash.test/api/v2/payment-gateway/transaction/init' => Http::response([
                'id' => 'zc-txn-1',
                'redirectUrl' => 'https://zaincash.test/pay/zc-txn-1',
            ], 200),
        ]);

        $redirectUrl = app(ZainCashBillingGateway::class)->createCheckout($plan, $owner->currentOrganization, 1);

        $this->assertSame('https://zaincash.test/pay/zc-txn-1', $redirectUrl);
        $this->assertDatabaseHas('gateway_payment_intents', [
            'gateway' => 'zaincash',
            'reference' => 'zc-txn-1',
            'organization_id' => $owner->current_organization_id,
            'plan_id' => $plan->id,
            'months' => 1,
            'amount' => $plan->price_cents,
            'currency' => 'IQD',
            'status' => 'pending',
        ]);
    }

    public function test_zaincash_webhook_grants_the_plan_on_a_validly_signed_paid_jwt(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();
        GatewayPaymentIntent::query()->create([
            'gateway' => 'zaincash',
            'reference' => 'zc-txn-2',
            'organization_id' => $owner->current_organization_id,
            'plan_id' => $plan->id,
            'months' => 2,
            'amount' => $plan->price_cents * 2,
            'currency' => 'IQD',
            'status' => 'pending',
        ]);

        $token = $this->makeJwt(['id' => 'zc-txn-2', 'status' => 'success']);

        $this->postJson('/api/v1/webhooks/zaincash', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.received', true);

        $intent = GatewayPaymentIntent::query()->where('reference', 'zc-txn-2')->firstOrFail();
        $this->assertSame('paid', $intent->status);
        $subscription = OrganizationSubscription::query()->where('organization_id', $owner->current_organization_id)->firstOrFail();
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame('zc-txn-2', $subscription->provider_subscription_id);
        $this->assertNull($subscription->granted_by_user_id);
    }

    public function test_zaincash_webhook_rejects_a_jwt_with_an_invalid_signature_without_mutating_anything(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();
        GatewayPaymentIntent::query()->create([
            'gateway' => 'zaincash',
            'reference' => 'zc-txn-3',
            'organization_id' => $owner->current_organization_id,
            'plan_id' => $plan->id,
            'months' => 1,
            'amount' => $plan->price_cents,
            'currency' => 'IQD',
            'status' => 'pending',
        ]);

        // Signed with the WRONG secret — a forged/tampered token.
        $forgedToken = $this->makeJwt(['id' => 'zc-txn-3', 'status' => 'success'], 'wrong-secret');

        $this->postJson('/api/v1/webhooks/zaincash', ['token' => $forgedToken])->assertOk();

        $this->assertSame('pending', GatewayPaymentIntent::query()->where('reference', 'zc-txn-3')->value('status'));
        $this->assertDatabaseMissing('organization_subscriptions', ['organization_id' => $owner->current_organization_id, 'plan_id' => $plan->id]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'billing.zaincash_verification_failed']);
        $this->assertSame(0, BillingWebhookEvent::query()->where('provider', 'zaincash')->count());
    }

    public function test_zaincash_webhook_rejects_an_amount_that_does_not_match_the_plans_current_price(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();
        $intent = GatewayPaymentIntent::query()->create([
            'gateway' => 'zaincash',
            'reference' => 'zc-txn-4',
            'organization_id' => $owner->current_organization_id,
            'plan_id' => $plan->id,
            'months' => 1,
            'amount' => 1,
            'currency' => 'IQD',
            'status' => 'pending',
        ]);

        $token = $this->makeJwt(['id' => 'zc-txn-4', 'status' => 'success']);

        $this->postJson('/api/v1/webhooks/zaincash', ['token' => $token])->assertOk();

        $this->assertSame('failed', $intent->fresh()->status);
        $this->assertDatabaseMissing('organization_subscriptions', ['organization_id' => $owner->current_organization_id, 'plan_id' => $plan->id]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'billing.zaincash_payment_amount_mismatch', 'auditable_id' => $intent->id]);
    }

    public function test_zaincash_webhook_is_idempotent_for_a_repeated_delivery_of_the_same_status(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();
        GatewayPaymentIntent::query()->create([
            'gateway' => 'zaincash',
            'reference' => 'zc-txn-5',
            'organization_id' => $owner->current_organization_id,
            'plan_id' => $plan->id,
            'months' => 1,
            'amount' => $plan->price_cents,
            'currency' => 'IQD',
            'status' => 'pending',
        ]);
        $token = $this->makeJwt(['id' => 'zc-txn-5', 'status' => 'success']);

        foreach ([1, 2] as $_) {
            $this->postJson('/api/v1/webhooks/zaincash', ['token' => $token])->assertOk();
        }

        $this->assertSame(1, BillingWebhookEvent::query()->where('provider', 'zaincash')->where('provider_event_id', 'zc-txn-5:paid')->count());
        $this->assertSame(1, PlatformAuditLog::query()->where('action', 'billing.zaincash_payment_succeeded')->count());
    }

    public function test_zaincash_return_endpoint_verifies_but_never_mutates_state(): void
    {
        $owner = User::factory()->create();
        $plan = $this->paidPlan();
        GatewayPaymentIntent::query()->create([
            'gateway' => 'zaincash',
            'reference' => 'zc-txn-6',
            'organization_id' => $owner->current_organization_id,
            'plan_id' => $plan->id,
            'months' => 1,
            'amount' => $plan->price_cents,
            'currency' => 'IQD',
            'status' => 'pending',
        ]);
        $token = $this->makeJwt(['id' => 'zc-txn-6', 'status' => 'success']);

        $this->getJson('/api/v1/billing/zaincash/return?token='.urlencode($token))
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.status', 'paid');

        // Read-only: the browser return leg must never grant anything —
        // only /webhooks/zaincash does.
        $this->assertSame('pending', GatewayPaymentIntent::query()->where('reference', 'zc-txn-6')->value('status'));
        $this->assertDatabaseMissing('organization_subscriptions', ['organization_id' => $owner->current_organization_id, 'plan_id' => $plan->id]);
    }

    private function makeJwt(array $claims, ?string $secret = null): string
    {
        $secret ??= self::SECRET;
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
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
