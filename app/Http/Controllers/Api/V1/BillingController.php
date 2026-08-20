<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Support\Billing\StripeBillingGateway;
use App\Support\Billing\StripeWebhookProcessor;
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

    public function checkout(Request $request, StripeBillingGateway $stripe): JsonResponse
    {
        $organization = $this->currentOrganization();
        $this->requireBillingOwner((int) $request->user()->id, (int) $organization->id);

        $validated = $request->validate(['plan_id' => ['required', 'integer']]);
        $plan = Plan::query()->whereKey($validated['plan_id'])->where('is_active', true)->firstOrFail();

        try {
            $checkoutUrl = $stripe->createCheckoutSession($plan, (int) $organization->id, $request->user());
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
