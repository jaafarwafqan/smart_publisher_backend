<?php

namespace App\Support\Billing;

/**
 * The normalized, gateway-agnostic result of PaymentGatewayContract::
 * verifyCallback(). Every field below is a claim the GATEWAY implementation
 * makes about an inbound callback — a controller/processor consuming this
 * must treat every field as untrusted input from the network until
 * $verified is true, and even then must still compare $amount/$currency
 * against the plan's own price in the database before mutating anything
 * (see BillingWebhookController/FibWebhookProcessor/ZainCashWebhookProcessor
 * — never trust the amount the client or provider callback reports).
 *
 * $verified is the ONLY field that certifies signature/JWT authenticity was
 * actually checked by the gateway implementation itself — a false here means
 * every other field is unauthenticated and MUST be discarded, not merely
 * treated with suspicion.
 */
final class PaymentCallbackResult
{
    public function __construct(
        public readonly bool $verified,
        public readonly string $reference,
        public readonly string $status,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly ?int $organizationId = null,
        public readonly ?int $planId = null,
        public readonly ?int $months = null,
        public readonly ?string $failureReason = null,
    ) {}

    public static function unverified(string $reference, ?string $failureReason = null): self
    {
        return new self(verified: false, reference: $reference, status: 'failed', failureReason: $failureReason);
    }

    public function isPaid(): bool
    {
        return $this->verified && $this->status === 'paid';
    }
}
