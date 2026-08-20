<?php

namespace App\Support\Billing;

use App\Models\BillingWebhookEvent;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Applies Stripe's source-of-truth subscription events idempotently. */
final class StripeWebhookProcessor
{
    /** @param array<string, mixed> $event */
    public function process(array $event): void
    {
        $eventId = $this->requiredString($event, 'id');
        $type = $this->requiredString($event, 'type');

        try {
            BillingWebhookEvent::query()->create([
                'provider' => 'stripe',
                'provider_event_id' => $eventId,
                'type' => $type,
                'payload' => $event,
            ]);
        } catch (QueryException $exception) {
            // Stripe retries any endpoint response it cannot confirm. The
            // database uniqueness constraint is the concurrency-safe claim;
            // never apply the same provider event twice.
            if (BillingWebhookEvent::query()->where('provider', 'stripe')->where('provider_event_id', $eventId)->exists()) {
                return;
            }

            throw $exception;
        }

        try {
            DB::transaction(function () use ($event, $eventId, $type): void {
                $storedEvent = BillingWebhookEvent::query()
                    ->where('provider', 'stripe')
                    ->where('provider_event_id', $eventId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (in_array($type, [
                    'checkout.session.completed',
                    'customer.subscription.created',
                    'customer.subscription.updated',
                    'customer.subscription.deleted',
                ], true)) {
                    $this->applySubscriptionEvent($type, $event['data']['object'] ?? []);
                }

                $storedEvent->forceFill(['processed_at' => now(), 'processing_error' => null])->save();
            });
        } catch (\Throwable $exception) {
            BillingWebhookEvent::query()
                ->where('provider', 'stripe')
                ->where('provider_event_id', $eventId)
                ->update(['processing_error' => mb_substr($exception->getMessage(), 0, 65_535)]);

            throw $exception;
        }
    }

    private function applySubscriptionEvent(string $type, mixed $object): void
    {
        if (! is_array($object)) {
            throw new LogicException('Stripe event object is invalid.');
        }

        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $organizationId = (int) ($metadata['organization_id'] ?? $object['client_reference_id'] ?? 0);
        if ($organizationId <= 0) {
            // The event is valid Stripe traffic but not one created by this
            // application. Acknowledge it without touching another tenant.
            return;
        }

        $plan = $this->resolvePlan($object, $metadata);
        if (! $plan) {
            throw new LogicException('Stripe subscription could not be matched to a local plan.');
        }

        $subscriptionId = $this->stringOrNull($object['id'] ?? null);
        if ($type === 'checkout.session.completed') {
            $subscriptionId = $this->stringOrNull($object['subscription'] ?? null);
        }

        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $organizationId],
            [
                'plan_id' => $plan->id,
                'status' => $type === 'checkout.session.completed' ? 'incomplete' : (string) ($object['status'] ?? 'incomplete'),
                'current_period_start' => $this->timestamp($object['current_period_start'] ?? null),
                'current_period_end' => $this->timestamp($object['current_period_end'] ?? null),
                'trial_ends_at' => $this->timestamp($object['trial_end'] ?? null),
                'canceled_at' => $this->timestamp($object['canceled_at'] ?? null),
                'provider_subscription_id' => $subscriptionId,
                'provider_customer_id' => $this->stringOrNull($object['customer'] ?? null),
            ],
        );
    }

    /** @param array<string, mixed> $object @param array<string, mixed> $metadata */
    private function resolvePlan(array $object, array $metadata): ?Plan
    {
        $planId = filter_var($metadata['plan_id'] ?? null, FILTER_VALIDATE_INT);
        if ($planId) {
            return Plan::query()->whereKey($planId)->where('is_active', true)->first();
        }

        $priceId = data_get($object, 'items.data.0.price.id');
        if (! is_string($priceId) || $priceId === '') {
            return null;
        }

        return Plan::query()->where('stripe_price_id', $priceId)->where('is_active', true)->first();
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new LogicException("Stripe event is missing {$key}.");
        }

        return $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function timestamp(mixed $value): ?Carbon
    {
        return is_numeric($value) ? Carbon::createFromTimestampUTC((int) $value) : null;
    }
}
