<?php

namespace App\Support\Billing;

use App\Models\Organization;
use App\Models\Plan;

/**
 * The shared boundary every payment provider integrates through —
 * introduced 2026-08-21 alongside the move to Iraqi payment gateways (FIB
 * first, then ZainCash) because none of them support recurring
 * subscriptions the way Stripe does. Every provider here is a one-time
 * payment: a successful charge extends the organization's current_period_end
 * by $months (see BillingPeriodGrantService), it does not create an
 * ongoing subscription the provider re-bills automatically.
 *
 * config('billing.gateway') (BILLING_GATEWAY in .env) selects which
 * implementation the container resolves this contract to — see
 * AppServiceProvider::register(). Stripe is kept behind this same
 * abstraction as a documented future option (an international/UAE-entity
 * gateway) rather than deleted; its existing recurring-subscription
 * behavior is unchanged; see StripeBillingGateway's own docblock for how it
 * maps onto a contract shaped around one-time, month-denominated payments.
 */
interface PaymentGatewayContract
{
    /**
     * Starts a payment for $months worth of $plan and returns the URL the
     * customer should be redirected to (or opened in a webview) to complete
     * it. Implementations must encode enough identifying information
     * (organization id, plan id, months) into the provider's own
     * checkout/transaction metadata that verifyCallback() can recover all
     * three from the callback alone.
     */
    public function createCheckout(Plan $plan, Organization $organization, int $months): string;

    /**
     * Verifies an inbound callback/webhook and returns a normalized result.
     * $payload is whatever raw context this specific gateway needs to
     * authenticate the callback — each implementation documents exactly
     * which keys it reads (e.g. the raw HTTP body + a signature header for
     * FIB, a signed JWT string for ZainCash). Implementations MUST verify
     * authenticity before setting PaymentCallbackResult::$verified to true;
     * callers MUST NOT act on any other field of the result when $verified
     * is false.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallback(array $payload): PaymentCallbackResult;

    /**
     * Actively polls the provider for a transaction's current status,
     * independent of any webhook delivery — used both as a fallback when a
     * webhook is delayed/missed and by the super-admin panel to manually
     * confirm a payment. Returns one of 'paid', 'pending', or 'failed'.
     */
    public function checkStatus(string $reference): string;
}
