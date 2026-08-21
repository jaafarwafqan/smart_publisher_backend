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
 * Applies ZainCash's signed JWT callback idempotently. Same claim-then-
 * process shape as StripeWebhookProcessor/FibWebhookProcessor, reusing the
 * same billing_webhook_events table (provider='zaincash'). Unlike FIB,
 * ZainCash's callback IS cryptographically signed (see ZainCashJwt), so
 * verification here means checking that signature rather than re-polling a
 * status API — but the "never touch a row before verifying" and "never
 * trust the reported amount" rules are identical either way.
 *
 * This processor is meant to be called ONLY from the server-to-server
 * webhook endpoint (BillingController::zainCashWebhook()) — the browser
 * return endpoint (zainCashReturn()) must never call this; see that
 * method's own docblock for why the production webhook, not the return
 * page, is this integration's source of truth.
 */
final class ZainCashWebhookProcessor
{
    public function __construct(
        private readonly ZainCashBillingGateway $gateway,
        private readonly BillingPeriodGrantService $grants,
        private readonly PlatformAuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $payload */
    public function process(array $payload, Request $request): void
    {
        $result = $this->gateway->verifyCallback($payload);

        if (! $result->verified) {
            $this->audit->record(
                $request,
                null,
                'billing.zaincash_verification_failed',
                GatewayPaymentIntent::class,
                null,
                null,
                ['reason' => $result->failureReason],
            );

            return;
        }

        $eventId = $result->reference.':'.$result->status;

        try {
            BillingWebhookEvent::query()->create([
                'provider' => 'zaincash',
                'provider_event_id' => $eventId,
                'type' => $result->status,
                'payload' => $payload,
            ]);
        } catch (QueryException $exception) {
            if (BillingWebhookEvent::query()->where('provider', 'zaincash')->where('provider_event_id', $eventId)->exists()) {
                return;
            }

            throw $exception;
        }

        try {
            DB::transaction(function () use ($result, $eventId, $request): void {
                $storedEvent = BillingWebhookEvent::query()
                    ->where('provider', 'zaincash')
                    ->where('provider_event_id', $eventId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->applyPayment($result, $request);

                $storedEvent->forceFill(['processed_at' => now(), 'processing_error' => null])->save();
            });
        } catch (\Throwable $exception) {
            BillingWebhookEvent::query()
                ->where('provider', 'zaincash')
                ->where('provider_event_id', $eventId)
                ->update(['processing_error' => mb_substr($exception->getMessage(), 0, 65_535)]);

            throw $exception;
        }
    }

    private function applyPayment(PaymentCallbackResult $result, Request $request): void
    {
        $intent = GatewayPaymentIntent::query()
            ->where('gateway', 'zaincash')
            ->where('reference', $result->reference)
            ->lockForUpdate()
            ->first();

        if (! $intent) {
            return;
        }

        if ($intent->status === 'paid') {
            return;
        }

        if ($result->status !== 'paid') {
            $intent->update(['status' => $result->status === 'pending' ? 'pending' : 'failed']);

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
        // the plan's CURRENT price and reject on any mismatch.
        $expectedAmount = ((int) $plan->price_cents) * $intent->months;
        if ($expectedAmount !== $intent->amount || $plan->currency !== $intent->currency) {
            $intent->update(['status' => 'failed']);

            $this->audit->record(
                $request,
                null,
                'billing.zaincash_payment_amount_mismatch',
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
            providerSubscriptionId: $result->reference,
        );

        $intent->update(['status' => 'paid']);

        $this->audit->record(
            $request,
            null,
            'billing.zaincash_payment_succeeded',
            GatewayPaymentIntent::class,
            $intent->id,
            null,
            ['plan_id' => $plan->id, 'months' => $intent->months, 'amount' => $intent->amount, 'currency' => $intent->currency],
            $organization->id,
        );
    }
}
