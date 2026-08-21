<?php

namespace App\Support\Billing;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * Small, organization-first Stripe boundary. Cashier's Billable trait is
 * designed around an individual billable Eloquent model; this product bills
 * an organization and already owns an organization_subscriptions projection.
 * Keeping Stripe's HTTP vocabulary here prevents provider details leaking
 * into controllers or entitlement checks while retaining a testable adapter.
 *
 * 2026-08-21: kept exactly as it was — recurring Stripe subscriptions, its
 * own dedicated BillingController::checkout()/stripeWebhook() routes, and
 * StripeWebhookProcessor entirely unchanged — and additionally implements
 * PaymentGatewayContract so it stays selectable behind the same
 * BILLING_GATEWAY abstraction as the new one-time-payment Iraqi gateways,
 * for a future international/UAE-entity option. $months is meaningless for
 * a recurring Stripe subscription and is accepted only for contract
 * conformance; createCheckoutSession()/the real checkout flow is unaffected.
 */
final class StripeBillingGateway implements PaymentGatewayContract
{
    public function createCheckout(Plan $plan, Organization $organization, int $months): string
    {
        $ownerMembership = $organization->primaryOwner ?? $organization->activeOwner;
        $owner = $ownerMembership?->user;
        if (! $owner) {
            throw new LogicException('This organization has no owner to bill.');
        }

        return $this->createCheckoutSession($plan, (int) $organization->id, $owner);
    }

    /**
     * $payload carries the raw context this gateway needs to authenticate a
     * Stripe webhook delivery: 'raw_body' (the exact HTTP body string) and
     * 'signature_header' (the Stripe-Signature header value) — signature
     * verification cannot be done from a pre-parsed array alone. This is a
     * conformance-only implementation; the application's real Stripe
     * webhook traffic is still handled by
     * BillingController::stripeWebhook() + StripeWebhookProcessor directly,
     * unchanged.
     */
    public function verifyCallback(array $payload): PaymentCallbackResult
    {
        $rawBody = (string) ($payload['raw_body'] ?? '');
        $signatureHeader = (string) ($payload['signature_header'] ?? '');

        if (! $this->validSignature($rawBody, $signatureHeader)) {
            return PaymentCallbackResult::unverified('', 'Invalid Stripe webhook signature.');
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event)) {
            return PaymentCallbackResult::unverified('', 'Invalid Stripe webhook payload.');
        }

        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        return new PaymentCallbackResult(
            verified: true,
            reference: (string) ($object['id'] ?? ''),
            status: in_array((string) ($object['status'] ?? ''), ['active', 'trialing'], true) ? 'paid' : 'pending',
            organizationId: isset($metadata['organization_id']) ? (int) $metadata['organization_id'] : null,
            planId: isset($metadata['plan_id']) ? (int) $metadata['plan_id'] : null,
        );
    }

    /** Polls Stripe directly for a subscription's current status. */
    public function checkStatus(string $reference): string
    {
        $response = $this->client()->get('/v1/subscriptions/'.$reference)->throw()->json();
        $status = is_array($response) ? (string) ($response['status'] ?? '') : '';

        return match ($status) {
            'active', 'trialing' => 'paid',
            'incomplete', 'past_due' => 'pending',
            default => 'failed',
        };
    }

    public function createCheckoutSession(Plan $plan, int $organizationId, User $customer): string
    {
        $priceId = trim((string) $plan->stripe_price_id);
        if ($priceId === '') {
            throw new LogicException('The selected plan is not configured for Stripe Checkout.');
        }

        $returnUrl = trim((string) config('billing.return_url'));
        if (! filter_var($returnUrl, FILTER_VALIDATE_URL)) {
            throw new LogicException('Billing return URL is not configured.');
        }

        $response = $this->client()->asForm()->post('/v1/checkout/sessions', [
            'mode' => 'subscription',
            'success_url' => $this->appendQuery($returnUrl, ['checkout' => 'success', 'session_id' => '{CHECKOUT_SESSION_ID}']),
            'cancel_url' => $this->appendQuery($returnUrl, ['checkout' => 'cancelled']),
            'client_reference_id' => (string) $organizationId,
            'customer_email' => $customer->email,
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'metadata' => [
                'organization_id' => (string) $organizationId,
                'plan_id' => (string) $plan->id,
            ],
            'subscription_data' => [
                'metadata' => [
                    'organization_id' => (string) $organizationId,
                    'plan_id' => (string) $plan->id,
                ],
            ],
        ])->throw()->json();

        $url = is_array($response) ? ($response['url'] ?? null) : null;
        if (! is_string($url) || $url === '') {
            throw new LogicException('Stripe did not return a Checkout URL.');
        }

        return $url;
    }

    public function createPortalSession(string $customerId): string
    {
        $returnUrl = trim((string) config('billing.return_url'));
        if ($customerId === '' || ! filter_var($returnUrl, FILTER_VALIDATE_URL)) {
            throw new LogicException('The billing portal is not available for this organization.');
        }

        $response = $this->client()->asForm()->post('/v1/billing_portal/sessions', [
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ])->throw()->json();

        $url = is_array($response) ? ($response['url'] ?? null) : null;
        if (! is_string($url) || $url === '') {
            throw new LogicException('Stripe did not return a customer portal URL.');
        }

        return $url;
    }

    private function client(): PendingRequest
    {
        $secret = trim((string) config('billing.stripe.secret_key'));
        if ($secret === '') {
            throw new LogicException('Stripe billing is not configured for this environment.');
        }

        return Http::baseUrl(rtrim((string) config('billing.stripe.api_base_url'), '/'))
            ->withToken($secret)
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5);
    }

    /**
     * Mirrors BillingController::validStripeSignature() exactly (same
     * config keys, same tolerance window, same hash_equals comparison) —
     * duplicated here rather than shared so verifyCallback() stays a
     * self-contained conformance method without reaching into a
     * controller's private method. The application's real webhook route
     * still uses the controller's own copy, unchanged.
     */
    private function validSignature(string $payload, string $header): bool
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

    /** @param array<string, string> $parameters */
    private function appendQuery(string $url, array $parameters): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
