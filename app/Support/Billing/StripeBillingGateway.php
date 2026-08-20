<?php

namespace App\Support\Billing;

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
 */
final class StripeBillingGateway
{
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

    /** @param array<string, string> $parameters */
    private function appendQuery(string $url, array $parameters): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
