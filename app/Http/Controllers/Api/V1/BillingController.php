<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Support\Billing\FibWebhookProcessor;
use App\Support\Billing\PaymentGatewayContract;
use App\Support\Billing\StripeBillingGateway;
use App\Support\Billing\StripeWebhookProcessor;
use App\Support\Billing\ZainCashBillingGateway;
use App\Support\Billing\ZainCashWebhookProcessor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

class BillingController extends Controller
{
    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => Plan::query()
                ->where('is_active', true)
                ->orderBy('price_cents')
                ->get()
                ->map(fn (Plan $plan): array => $this->planPayload($plan))
                ->values(),
        ]);
    }

    public function subscription(Request $request): JsonResponse
    {
        $organization = $this->currentOrganization();
        $subscription = $organization->subscription()->with('plan')->first();

        return response()->json([
            'data' => [
                'organization_id' => $organization->id,
                'subscription' => $subscription ? [
                    'status' => $subscription->status,
                    'plan' => $subscription->plan ? $this->planPayload($subscription->plan) : null,
                    'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                    'cancel_at_period_end' => $subscription->canceled_at !== null && $subscription->current_period_end !== null,
                ] : null,
            ],
        ]);
    }

    /**
     * $gateway resolves to whichever provider BILLING_GATEWAY selects — see
     * PaymentGatewayContract's own docblock. months is only meaningful for
     * the one-time-payment gateways (FIB/ZainCash); Stripe's implementation
     * ignores it (a Stripe subscription bills on its own recurring cycle).
     */
    public function checkout(Request $request, PaymentGatewayContract $gateway): JsonResponse
    {
        $organization = $this->currentOrganization();
        $this->requireBillingOwner((int) $request->user()->id, (int) $organization->id);

        $validated = $request->validate([
            'plan_id' => ['required', 'integer'],
            'months' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
        $plan = Plan::query()->whereKey($validated['plan_id'])->where('is_active', true)->firstOrFail();

        try {
            $checkoutUrl = $gateway->createCheckout($plan, $organization, (int) ($validated['months'] ?? 1));
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => ['checkout_url' => $checkoutUrl]], 201);
    }

    public function portal(Request $request, StripeBillingGateway $stripe): JsonResponse
    {
        $organization = $this->currentOrganization();
        $this->requireBillingOwner((int) $request->user()->id, (int) $organization->id);
        $customerId = (string) $organization->subscription?->provider_customer_id;

        try {
            $portalUrl = $stripe->createPortalSession($customerId);
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => ['portal_url' => $portalUrl]]);
    }

    public function stripeWebhook(Request $request, StripeWebhookProcessor $processor): JsonResponse
    {
        $payload = $request->getContent();
        if (! $this->validStripeSignature($payload, (string) $request->header('Stripe-Signature'))) {
            return response()->json(['message' => 'Invalid Stripe webhook signature.'], 400);
        }

        $event = json_decode($payload, true);
        if (! is_array($event)) {
            return response()->json(['message' => 'Invalid Stripe webhook payload.'], 400);
        }

        $processor->process($event);

        return response()->json(['received' => true]);
    }

    /**
     * FIB's callback carries no signature — see FibBillingGateway's own
     * docblock. FibWebhookProcessor is what actually re-verifies the claim
     * against FIB's own status API before touching anything.
     */
    public function fibWebhook(Request $request, FibWebhookProcessor $processor): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid FIB webhook payload.'], 400);
        }

        $processor->process($payload, $request);

        return response()->json(['received' => true]);
    }

    /**
     * The server-to-server ZainCash delivery — the source of truth for a
     * ZainCash payment. Its JWT signature is verified inside
     * ZainCashWebhookProcessor before any row is touched.
     */
    public function zainCashWebhook(Request $request, ZainCashWebhookProcessor $processor): JsonResponse
    {
        $payload = $request->all();
        $processor->process($payload, $request);

        return response()->json(['received' => true]);
    }

    /**
     * The customer's BROWSER lands here after completing (or abandoning) a
     * ZainCash payment, carrying the same signed JWT the server-to-server
     * webhook above also receives. This endpoint is deliberately read-only:
     * a browser redirect is client-controlled navigation (repeatable,
     * skippable, not guaranteed delivery) and must never be the trigger
     * that grants a paid period — only /webhooks/zaincash (zainCashWebhook()
     * above) ever calls BillingPeriodGrantService. This exists purely so the
     * frontend can show an immediate, verified "payment received" state
     * without waiting on the webhook's own delivery latency.
     */
    public function zainCashReturn(Request $request, ZainCashBillingGateway $gateway): JsonResponse
    {
        $result = $gateway->verifyCallback(['token' => (string) $request->query('token', '')]);

        return response()->json([
            'data' => [
                'verified' => $result->verified,
                'status' => $result->status,
            ],
        ]);
    }

    private function currentOrganization(): Organization
    {
        return Organization::query()->findOrFail(app(TenantContext::class)->get());
    }

    private function requireBillingOwner(int $userId, int $organizationId): void
    {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        if (! $membership || $membership->role !== OrganizationRole::Owner) {
            abort(403, 'Only an organization owner can manage billing.');
        }
    }

    private function validStripeSignature(string $payload, string $header): bool
    {
        $secret = (string) config('billing.stripe.webhook_secret');
        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 't' && ctype_digit((string) $value)) {
                $timestamp = (int) $value;
            }
            if ($key === 'v1' && is_string($value)) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - $timestamp) > (int) config('billing.stripe.webhook_tolerance_seconds')) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function planPayload(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'price_cents' => $plan->price_cents,
            'currency' => $plan->currency,
            'billing_interval' => $plan->billing_interval,
            'limits' => $plan->limits,
            'is_checkout_available' => $plan->stripe_price_id !== null,
        ];
    }
}
