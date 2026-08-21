<?php

namespace App\Support\Billing;

use App\Models\GatewayPaymentIntent;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;

/**
 * ZainCash — no official Laravel SDK (unlike FIB), integrated by hand
 * against its documented OAuth2 + JWT-callback flow: an OAuth2
 * client-credentials token from /oauth2/token authorizes a
 * POST /api/v2/payment-gateway/transaction/init, the customer is redirected
 * to the returned redirectUrl, and ZainCash later delivers a JWT (signed
 * with this merchant's own client secret — see ZainCashJwt) both to the
 * customer's browser return (successUrl/failureUrl) and, independently, to
 * a server-to-server production webhook. Per this integration's security
 * rules, ONLY the webhook delivery is ever treated as authoritative — see
 * ZainCashWebhookProcessor and BillingController::zainCashReturn()'s own
 * docblock for why the browser return path never mutates anything. IQD is
 * the only currency ZainCash supports here; MSISDN is this merchant's own
 * phone number, not a customer's.
 */
final class ZainCashBillingGateway implements PaymentGatewayContract
{
    public function createCheckout(Plan $plan, Organization $organization, int $months): string
    {
        if ($months < 1) {
            throw new LogicException('A ZainCash checkout must cover at least one month.');
        }

        $currency = (string) ($plan->currency ?? '');
        if ($currency !== 'IQD') {
            throw new LogicException('ZainCash only accepts IQD — this plan is not priced in IQD.');
        }

        $priceCents = $plan->price_cents;
        if ($priceCents === null || $priceCents <= 0) {
            throw new LogicException('The selected plan is not configured for ZainCash checkout.');
        }

        $successUrl = trim((string) config('billing.zaincash.success_url'));
        $failureUrl = trim((string) config('billing.zaincash.failure_url'));
        $msisdn = trim((string) config('billing.zaincash.msisdn'));
        if (! filter_var($successUrl, FILTER_VALIDATE_URL) || ! filter_var($failureUrl, FILTER_VALIDATE_URL) || $msisdn === '') {
            throw new LogicException('ZainCash billing is not configured for this environment.');
        }

        $amount = $priceCents * $months;
        $orderId = (string) Str::uuid();

        $response = $this->client()->withToken($this->fetchAccessToken())->post('/api/v2/payment-gateway/transaction/init', [
            'amount' => $amount,
            'serviceType' => sprintf('%s — %d month(s)', $plan->name, $months),
            'msisdn' => $msisdn,
            'orderId' => $orderId,
            'redirectUrl' => $successUrl,
            'successUrl' => $successUrl,
            'failureUrl' => $failureUrl,
        ])->throw()->json();

        $reference = is_array($response) ? ($response['id'] ?? $orderId) : $orderId;
        $redirectUrl = is_array($response) ? ($response['redirectUrl'] ?? null) : null;
        if (! is_string($redirectUrl) || $redirectUrl === '') {
            throw new LogicException('ZainCash did not return a redirect URL.');
        }

        GatewayPaymentIntent::query()->create([
            'gateway' => 'zaincash',
            'reference' => (string) $reference,
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'months' => $months,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
        ]);

        return $redirectUrl;
    }

    /**
     * $payload must contain the raw JWT string under 'token' — the same
     * token both the browser return and the server-to-server webhook
     * carry. Signature verification happens here, before any claim is
     * trusted; a failed verification returns an unverified result and
     * MUST NOT be used to mutate anything.
     */
    public function verifyCallback(array $payload): PaymentCallbackResult
    {
        $token = (string) ($payload['token'] ?? '');
        $secret = (string) config('billing.zaincash.client_secret');

        $claims = ZainCashJwt::verify($token, $secret);
        if ($claims === null) {
            return PaymentCallbackResult::unverified('', 'ZainCash JWT signature verification failed.');
        }

        $reference = (string) ($claims['id'] ?? $claims['orderId'] ?? '');
        if ($reference === '') {
            return PaymentCallbackResult::unverified('', 'ZainCash JWT did not carry a transaction id.');
        }

        $intent = GatewayPaymentIntent::query()->where('gateway', 'zaincash')->where('reference', $reference)->first();
        if (! $intent) {
            return PaymentCallbackResult::unverified($reference, 'No matching ZainCash payment intent was found.');
        }

        return new PaymentCallbackResult(
            verified: true,
            reference: $reference,
            status: $this->normalizeStatus((string) ($claims['status'] ?? '')),
            amount: $intent->amount,
            currency: $intent->currency,
            organizationId: $intent->organization_id,
            planId: $intent->plan_id,
            months: $intent->months,
        );
    }

    public function checkStatus(string $reference): string
    {
        $response = $this->client()
            ->withToken($this->fetchAccessToken())
            ->get('/api/v2/payment-gateway/transaction/'.$reference)
            ->throw();

        return $this->normalizeStatus((string) $response->json('status'));
    }

    private function fetchAccessToken(): string
    {
        $clientId = trim((string) config('billing.zaincash.client_id'));
        $clientSecret = trim((string) config('billing.zaincash.client_secret'));
        if ($clientId === '' || $clientSecret === '') {
            throw new LogicException('ZainCash billing is not configured for this environment.');
        }

        $response = $this->client()->asForm()->post('/oauth2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ])->throw()->json();

        $token = is_array($response) ? ($response['access_token'] ?? null) : null;
        if (! is_string($token) || $token === '') {
            throw new LogicException('ZainCash did not return an access token.');
        }

        return $token;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('billing.zaincash.base_url'), '/'))
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5);
    }

    private function normalizeStatus(string $status): string
    {
        return match (mb_strtolower($status)) {
            'success', 'paid', 'completed' => 'paid',
            'pending', 'processing' => 'pending',
            default => 'failed',
        };
    }
}
