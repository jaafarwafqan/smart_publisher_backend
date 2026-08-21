<?php

namespace App\Support\Billing;

use App\Models\GatewayPaymentIntent;
use App\Models\Organization;
use App\Models\Plan;
use FirstIraqiBank\FIBPaymentSDK\Model\FibPayment;
use FirstIraqiBank\FIBPaymentSDK\Services\FIBPaymentIntegrationService;
use LogicException;

/**
 * FIB (First Iraqi Bank), integrated via the official
 * first-iraqi-bank/fib-laravel-payment-sdk package. One-time payment only
 * — see PaymentGatewayContract's own docblock for why $months extends
 * current_period_end rather than creating a recurring subscription.
 *
 * FIB's webhook is documented as carrying only a payment id and a status —
 * no signature, no echoed metadata. Two things follow from that:
 *  1. gateway_payment_intents (written here at checkout time) is the only
 *     place organization/plan/months can be recovered from a bare payment
 *     id later — see FibWebhookProcessor.
 *  2. Because the webhook itself is unsigned, "verification" for FIB means
 *     actively re-querying FIB's own status API (checkStatus(), which is
 *     authenticated via OAuth2 client-credentials) rather than trusting
 *     whatever status the webhook body claims — see verifyCallback().
 */
final class FibBillingGateway implements PaymentGatewayContract
{
    /**
     * Depends on the concrete FIBPaymentIntegrationService rather than its
     * own FIBPaymentIntegrationServiceInterface — the SDK's interface
     * declares createPayment() with only 4 parameters, while the concrete
     * class actually accepts $extraData/$expiresIn too (an inconsistency in
     * the vendor package itself); the container still binds one instance
     * to both.
     */
    public function __construct(private readonly FIBPaymentIntegrationService $fib) {}

    public function createCheckout(Plan $plan, Organization $organization, int $months): string
    {
        if ($months < 1) {
            throw new LogicException('A FIB checkout must cover at least one month.');
        }

        $currency = (string) ($plan->currency ?? '');
        if ($currency !== 'IQD') {
            throw new LogicException('FIB only accepts IQD — this plan is not priced in IQD.');
        }

        $priceCents = $plan->price_cents;
        if ($priceCents === null || $priceCents <= 0) {
            throw new LogicException('The selected plan is not configured for FIB checkout.');
        }

        $callbackUrl = trim((string) config('fib.callback'));
        if (! filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
            throw new LogicException('FIB billing is not configured for this environment (missing callback URL).');
        }

        $amount = $priceCents * $months;

        $response = $this->fib->createPayment(
            $amount,
            $callbackUrl,
            sprintf('%s — %d month(s)', $plan->name, $months),
            trim((string) config('billing.return_url')),
            [
                'organization_id' => (string) $organization->id,
                'plan_id' => (string) $plan->id,
                'months' => (string) $months,
            ],
        );

        if (! $response->successful()) {
            throw new LogicException('FIB did not accept the payment request: '.$response->body());
        }

        $paymentId = $response->json('paymentId');
        $checkoutUrl = $response->json('personalAppLink');
        if (! is_string($paymentId) || $paymentId === '' || ! is_string($checkoutUrl) || $checkoutUrl === '') {
            throw new LogicException('FIB did not return a payment id and checkout link.');
        }

        GatewayPaymentIntent::query()->create([
            'gateway' => 'fib',
            'reference' => $paymentId,
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'months' => $months,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
        ]);

        return $checkoutUrl;
    }

    /**
     * $payload is the FIB callback body — documented as carrying only
     * paymentId and status. Since that carries no signature, authenticity
     * comes from actively re-querying FIB's own status API below rather
     * than trusting the body; a mismatch between what the webhook claims
     * and what FIB's API actually reports is treated as unverified.
     */
    public function verifyCallback(array $payload): PaymentCallbackResult
    {
        $paymentId = $payload['paymentId'] ?? $payload['payment_id'] ?? null;
        if (! is_string($paymentId) || $paymentId === '') {
            return PaymentCallbackResult::unverified('', 'FIB callback is missing paymentId.');
        }

        $intent = GatewayPaymentIntent::query()->where('gateway', 'fib')->where('reference', $paymentId)->first();
        if (! $intent) {
            return PaymentCallbackResult::unverified($paymentId, 'No matching FIB payment intent was found.');
        }

        $confirmedStatus = $this->checkStatus($paymentId);

        return new PaymentCallbackResult(
            verified: true,
            reference: $paymentId,
            status: $confirmedStatus,
            amount: $intent->amount,
            currency: $intent->currency,
            organizationId: $intent->organization_id,
            planId: $intent->plan_id,
            months: $intent->months,
        );
    }

    public function checkStatus(string $reference): string
    {
        $response = $this->fib->checkPaymentStatus($reference);
        if (! $response->successful()) {
            throw new LogicException('Could not confirm FIB payment status: '.$response->body());
        }

        return $this->normalizeStatus((string) $response->json('status'));
    }

    private function normalizeStatus(string $fibStatus): string
    {
        return match ($fibStatus) {
            FibPayment::PAID => 'paid',
            FibPayment::PENDING, FibPayment::UNPAID => 'pending',
            default => 'failed',
        };
    }
}
