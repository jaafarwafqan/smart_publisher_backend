<?php

namespace App\Support\Billing;

use App\Models\BillingWebhookEvent;
use App\Models\GatewayPaymentIntent;
use App\Models\Organization;
use App\Models\Plan;
use App\Support\Platform\PlatformAuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Applies FIB's payment callback idempotently — same claim-then-process
 * shape as StripeWebhookProcessor, reusing the same billing_webhook_events
 * table (provider='fib'). FIB's callback carries no delivery id, only a
 * payment id + status, so the dedupe key is reference:status — each
 * distinct status a payment passes through (e.g. PENDING then PAID) claims
 * its own row, while a genuine retry of the exact same delivery is a no-op.
 */
final class FibWebhookProcessor
{
    public function __construct(
        private readonly FibBillingGateway $gateway,
        private readonly BillingPeriodGrantService $grants,
        private readonly PlatformAuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $payload */
    public function process(array $payload, Request $request): void
    {
        $paymentId = (string) ($payload['paymentId'] ?? $payload['payment_id'] ?? '');
        $rawStatus = (string) ($payload['status'] ?? '');
        $eventId = $paymentId.':'.$rawStatus;

        if ($paymentId === '') {
            return;
        }

        try {
            BillingWebhookEvent::query()->create([
                'provider' => 'fib',
                'provider_event_id' => $eventId,
                'type' => $rawStatus,
                'payload' => $payload,
            ]);
        } catch (QueryException $exception) {
            if (BillingWebhookEvent::query()->where('provider', 'fib')->where('provider_event_id', $eventId)->exists()) {
                return;
            }

            throw $exception;
        }

        try {
            DB::transaction(function () use ($paymentId, $eventId, $request): void {
                $storedEvent = BillingWebhookEvent::query()
                    ->where('provider', 'fib')
                    ->where('provider_event_id', $eventId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->applyPayment($paymentId, $request);

                $storedEvent->forceFill(['processed_at' => now(), 'processing_error' => null])->save();
            });
        } catch (\Throwable $exception) {
            BillingWebhookEvent::query()
                ->where('provider', 'fib')
                ->where('provider_event_id', $eventId)
                ->update(['processing_error' => mb_substr($exception->getMessage(), 0, 65_535)]);

            throw $exception;
        }
    }

    private function applyPayment(string $paymentId, Request $request): void
    {
        $intent = GatewayPaymentIntent::query()
            ->where('gateway', 'fib')
            ->where('reference', $paymentId)
            ->lockForUpdate()
            ->first();

        if (! $intent) {
            // Not a payment this application created — acknowledge without
            // touching anything, exactly like Stripe's own "not our event"
            // early return.
            return;
        }

        if ($intent->status === 'paid') {
            // Already applied by a previous delivery of the PAID status.
            return;
        }

        // Never trust the webhook body's claimed status — actively
        // re-confirm against FIB's own authenticated status API.
        $confirmedStatus = $this->gateway->checkStatus($paymentId);

        if ($confirmedStatus !== 'paid') {
            $intent->update(['status' => $confirmedStatus === 'pending' ? 'pending' : 'failed']);

            return;
        }

        $plan = Plan::query()->find($intent->plan_id);
        $organization = Organization::query()->find($intent->organization_id);
        if (! $plan || ! $organization) {
            $intent->update(['status' => 'failed']);

            return;
        }

        // Never trust the amount reported anywhere upstream of this
        // application's own database — recompute the expected charge from
        // the plan's CURRENT price and reject on any mismatch rather than
        // granting a period the organization didn't actually pay for.
        $expectedAmount = ((int) $plan->price_cents) * $intent->months;
        if ($expectedAmount !== $intent->amount || $plan->currency !== $intent->currency) {
            $intent->update(['status' => 'failed']);

            $this->audit->record(
                $request,
                null,
                'billing.fib_payment_amount_mismatch',
                GatewayPaymentIntent::class,
                $intent->id,
                null,
                ['expected_amount' => $expectedAmount, 'intent_amount' => $intent->amount, 'currency' => $plan->currency],
                $organization->id,
            );

            return;
        }

        $this->grants->grantPlan(
            $organization,
            $plan,
            $intent->months,
            grantedBy: null,
            grantedReason: null,
            providerSubscriptionId: $paymentId,
        );

        $intent->update(['status' => 'paid']);

        $this->audit->record(
            $request,
            null,
            'billing.fib_payment_succeeded',
            GatewayPaymentIntent::class,
            $intent->id,
            null,
            ['plan_id' => $plan->id, 'months' => $intent->months, 'amount' => $intent->amount, 'currency' => $intent->currency],
            $organization->id,
        );
    }
}
