<?php

namespace Tests\Feature;

use App\Models\BillingWebhookEvent;
use App\Models\OrganizationMembership;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Support\Billing\QuotaGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingAndWebSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_start_stripe_checkout_for_a_configured_paid_plan(): void
    {
        config()->set('billing.stripe.secret_key', 'sk_test_example');
        config()->set('billing.return_url', 'https://app.example.test/billing');
        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.test/c/pay/cs_test_123',
            ]),
        ]);

        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Professional',
            'slug' => 'professional',
            'price_cents' => 4900,
            'currency' => 'usd',
            'billing_interval' => 'month',
            'stripe_price_id' => 'price_professional_monthly',
            'limits' => ['max_team_members' => 20, 'max_social_accounts' => 20, 'max_scheduled_posts_per_month' => 500, QuotaGates::FEATURE_APPROVAL_WORKFLOW => true, QuotaGates::FEATURE_AUDIT_LOG => true, QuotaGates::FEATURE_BRANCHES => true, QuotaGates::FEATURE_ADVANCED_ANALYTICS => true],
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $this->withHeader('X-Organization-Id', (string) $owner->current_organization_id)
            ->postJson('/api/v1/billing/checkout', ['plan_id' => $plan->id])
            ->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/c/pay/cs_test_123');

        Http::assertSent(function ($request) use ($owner, $plan): bool {
            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $request['mode'] === 'subscription'
                && $request['line_items'][0]['price'] === $plan->stripe_price_id
                && $request['metadata']['organization_id'] === (string) $owner->current_organization_id;
        });
    }

    public function test_a_non_owner_cannot_start_checkout(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $owner->current_organization_id,
            'user_id' => $member->id,
            'role' => 'editor',
            'status' => 'active',
        ]);

        Sanctum::actingAs($member);

        $this->withHeader('X-Organization-Id', (string) $owner->current_organization_id)
            ->postJson('/api/v1/billing/checkout', ['plan_id' => Plan::query()->where('slug', 'free')->value('id')])
            ->assertForbidden();
    }

    public function test_stripe_webhook_updates_the_subscription_exactly_once(): void
    {
        config()->set('billing.stripe.webhook_secret', 'whsec_test');
        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Professional',
            'slug' => 'professional',
            'price_cents' => 4900,
            'currency' => 'usd',
            'billing_interval' => 'month',
            'stripe_price_id' => 'price_professional_monthly',
            'limits' => ['max_team_members' => 20, 'max_social_accounts' => 20, 'max_scheduled_posts_per_month' => 500, QuotaGates::FEATURE_APPROVAL_WORKFLOW => true, QuotaGates::FEATURE_AUDIT_LOG => true, QuotaGates::FEATURE_BRANCHES => true, QuotaGates::FEATURE_ADVANCED_ANALYTICS => true],
            'is_active' => true,
        ]);
        $payload = json_encode([
            'id' => 'evt_subscription_123',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_123',
                'customer' => 'cus_123',
                'status' => 'active',
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'metadata' => [
                    'organization_id' => (string) $owner->current_organization_id,
                    'plan_id' => (string) $plan->id,
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        foreach ([1, 2] as $_) {
            $this->call(
                'POST',
                '/api/v1/webhooks/stripe',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
                $payload,
            )->assertOk()->assertJsonPath('data.received', true);
        }

        $subscription = OrganizationSubscription::query()
            ->where('organization_id', $owner->current_organization_id)
            ->firstOrFail();
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame('active', $subscription->status);
        $this->assertSame('sub_123', $subscription->provider_subscription_id);
        $this->assertSame('cus_123', $subscription->provider_customer_id);
        $this->assertSame(1, BillingWebhookEvent::query()->where('provider_event_id', 'evt_subscription_123')->count());
    }

    public function test_stripe_webhook_still_applies_a_cancellation_after_the_plan_was_deactivated(): void
    {
        // Regression: resolvePlan() used to filter where('is_active', true)
        // even when connecting an INCOMING webhook event back to a plan a
        // subscription was already on. Deactivating a plan (a normal admin
        // action — retiring an old tier) while any organization still held
        // a subscription on it meant that organization's eventual
        // customer.subscription.deleted event threw, propagated uncaught
        // out of BillingController::stripeWebhook() as a 500, and Stripe
        // retried forever with the local subscription never marked
        // canceled.
        config()->set('billing.stripe.webhook_secret', 'whsec_test');
        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Retiring Tier',
            'slug' => 'retiring-tier',
            'price_cents' => 1900,
            'currency' => 'usd',
            'billing_interval' => 'month',
            'stripe_price_id' => 'price_retiring_tier',
            'limits' => ['max_team_members' => 10, 'max_social_accounts' => 10, 'max_scheduled_posts_per_month' => 200, QuotaGates::FEATURE_APPROVAL_WORKFLOW => true, QuotaGates::FEATURE_AUDIT_LOG => true, QuotaGates::FEATURE_BRANCHES => true, QuotaGates::FEATURE_ADVANCED_ANALYTICS => true],
            'is_active' => true,
        ]);
        // User::factory()->create() already auto-provisions a Free
        // subscription via PersonalOrganizationProvisioner; move it onto
        // the paid plan under test rather than inserting a second row
        // (organization_id is unique).
        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id],
            [
                'plan_id' => $plan->id,
                'status' => 'active',
                'provider_subscription_id' => 'sub_retiring_123',
                'provider_customer_id' => 'cus_retiring_123',
            ],
        );

        // The admin retires the plan — new checkouts stop, but this
        // organization is still actively subscribed to it.
        $plan->forceFill(['is_active' => false])->save();

        $payload = json_encode([
            'id' => 'evt_subscription_deleted_123',
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => [
                'id' => 'sub_retiring_123',
                'customer' => 'cus_retiring_123',
                'status' => 'canceled',
                'canceled_at' => now()->timestamp,
                'metadata' => [
                    'organization_id' => (string) $owner->current_organization_id,
                    'plan_id' => (string) $plan->id,
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $this->call(
            'POST',
            '/api/v1/webhooks/stripe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
            $payload,
        )->assertOk()->assertJsonPath('data.received', true);

        $subscription = OrganizationSubscription::query()
            ->where('organization_id', $owner->current_organization_id)
            ->firstOrFail();
        $this->assertSame('canceled', $subscription->status);
        $this->assertNotNull($subscription->canceled_at);
    }

    public function test_web_sessions_receive_http_only_cookies_not_json_tokens(): void
    {
        config()->set('auth.web_token_cookies.enabled', true);
        config()->set('auth.web_token_cookies.secure', false);

        $response = $this->withHeader('X-SP-Web-Client', '1')->postJson('/api/v1/auth/register', [
            'name' => 'Browser User',
            'email' => 'browser@example.com',
            'password' => 'BrandNewPassword456',
            'password_confirmation' => 'BrandNewPassword456',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.access_token', '')
            ->assertJsonPath('data.refresh_token', '')
            ->assertCookie('sp_access_token')
            ->assertCookie('sp_refresh_token');
    }

    public function test_web_session_cookie_is_authenticated_by_the_normal_sanctum_guard(): void
    {
        config()->set('auth.web_token_cookies.enabled', true);
        $user = User::factory()->create();
        $token = $user->createToken('access-token:flutter-web')->plainTextToken;

        $this->withHeader('X-SP-Web-Client', '1')
            ->withCredentials()
            ->withUnencryptedCookie('sp_access_token', $token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.access_token', null);
    }
}
