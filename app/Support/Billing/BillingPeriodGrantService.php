<?php

namespace App\Support\Billing;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single place current_period_end is ever advanced — 2026-08-21,
 * alongside the move to prepaid Iraqi gateways (FIB/ZainCash), which have no
 * concept of a recurring subscription: a successful payment is a one-time
 * charge that must extend the organization's paid-for period by however
 * many months it covered. A super-admin's manual grant/extend and a real
 * paid-gateway renewal are, in this model, literally the same operation —
 * both call the methods below rather than each hand-rolling their own
 * date math, so "never lose remaining days, never grant free days" only has
 * to be gotten right in extendFrom() once. See AdminSubscriptionController
 * for the manual side and FibWebhookProcessor/ZainCashWebhookProcessor for
 * the paid side.
 */
class BillingPeriodGrantService
{
    /**
     * Assigns $plan and grants $months of paid-for period. Used both by
     * AdminSubscriptionController::grant() (manual — $grantedBy/$grantedReason
     * set, $providerSubscriptionId null) and by a successful gateway payment
     * ($grantedBy/$grantedReason null, $providerSubscriptionId the
     * provider's own transaction/payment reference).
     */
    public function grantPlan(
        Organization $organization,
        Plan $plan,
        int $months,
        ?User $grantedBy = null,
        ?string $grantedReason = null,
        ?string $providerSubscriptionId = null,
        ?string $providerCustomerId = null,
    ): OrganizationSubscription {
        return DB::transaction(function () use ($organization, $plan, $months, $grantedBy, $grantedReason, $providerSubscriptionId, $providerCustomerId): OrganizationSubscription {
            $existing = $this->lockExisting($organization);
            $subscription = $existing ?? new OrganizationSubscription(['organization_id' => $organization->id]);

            $subscription->plan_id = $plan->id;
            $subscription->status = 'active';
            $subscription->current_period_start ??= now();
            $subscription->current_period_end = $this->extendFrom($existing, months: $months);
            $subscription->canceled_at = null;

            // A paid renewal always supplies its own transaction reference;
            // a manual grant passes null and must not overwrite whatever
            // provider identifiers a real payment left behind earlier.
            if ($providerSubscriptionId !== null) {
                $subscription->provider_subscription_id = $providerSubscriptionId;
            }
            if ($providerCustomerId !== null) {
                $subscription->provider_customer_id = $providerCustomerId;
            }

            $subscription->granted_by_user_id = $grantedBy?->id;
            $subscription->granted_reason = $grantedReason;
            $subscription->save();

            return $subscription;
        });
    }

    /**
     * Extends the CURRENT plan's period without changing which plan is
     * assigned — AdminSubscriptionController::extend() only.
     */
    public function extendPeriod(
        Organization $organization,
        int $months,
        int $days,
        User $grantedBy,
        string $grantedReason,
    ): OrganizationSubscription {
        return DB::transaction(function () use ($organization, $months, $days, $grantedBy, $grantedReason): OrganizationSubscription {
            $existing = $this->lockExisting($organization);
            if (! $existing || ! $existing->plan_id) {
                throw new LogicException('This organization has no subscription to extend yet — grant a plan first.');
            }

            $existing->current_period_end = $this->extendFrom($existing, months: $months, days: $days);
            $existing->status = 'active';
            $existing->canceled_at = null;
            $existing->granted_by_user_id = $grantedBy->id;
            $existing->granted_reason = $grantedReason;
            $existing->save();

            return $existing;
        });
    }

    /**
     * Grants a trial period on whatever plan the organization is already
     * assigned (or $fallbackPlan, typically Free, if it has none yet) —
     * the trial route deliberately takes no plan_id, only {days, reason}.
     */
    public function grantTrial(
        Organization $organization,
        int $days,
        User $grantedBy,
        string $grantedReason,
        ?Plan $fallbackPlan = null,
    ): OrganizationSubscription {
        return DB::transaction(function () use ($organization, $days, $grantedBy, $grantedReason, $fallbackPlan): OrganizationSubscription {
            $existing = $this->lockExisting($organization);
            $subscription = $existing ?? new OrganizationSubscription(['organization_id' => $organization->id]);

            if (! $subscription->plan_id) {
                if (! $fallbackPlan) {
                    throw new LogicException('This organization has no plan assigned and no fallback plan was supplied for the trial.');
                }
                $subscription->plan_id = $fallbackPlan->id;
            }

            $newPeriodEnd = $this->extendFrom($existing, days: $days);
            $subscription->status = 'trialing';
            $subscription->trial_ends_at = $newPeriodEnd;
            $subscription->current_period_end = $newPeriodEnd;
            $subscription->current_period_start ??= now();
            $subscription->canceled_at = null;
            $subscription->granted_by_user_id = $grantedBy->id;
            $subscription->granted_reason = $grantedReason;
            $subscription->save();

            return $subscription;
        });
    }

    /** Reverts an organization to the Free plan, immediately applying Free's limits. */
    public function revertToFree(
        Organization $organization,
        Plan $freePlan,
        User $revertedBy,
        string $reason,
    ): OrganizationSubscription {
        return DB::transaction(function () use ($organization, $freePlan, $revertedBy, $reason): OrganizationSubscription {
            $existing = $this->lockExisting($organization);
            $subscription = $existing ?? new OrganizationSubscription(['organization_id' => $organization->id]);

            $subscription->plan_id = $freePlan->id;
            $subscription->status = 'active';
            $subscription->current_period_start ??= now();
            $subscription->current_period_end = null;
            $subscription->trial_ends_at = null;
            $subscription->canceled_at = null;
            $subscription->provider_subscription_id = null;
            $subscription->granted_by_user_id = $revertedBy->id;
            $subscription->granted_reason = $reason;
            $subscription->save();

            return $subscription;
        });
    }

    private function lockExisting(Organization $organization): ?OrganizationSubscription
    {
        return OrganizationSubscription::query()
            ->where('organization_id', $organization->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The one shared "never lose remaining days, never grant free days"
     * rule: base the new end date on the CURRENT period end if it is still
     * in the future, otherwise on now(). Identical whether the caller is a
     * manual admin grant/extend/trial or a real paid-gateway renewal.
     */
    private function extendFrom(?OrganizationSubscription $existing, int $months = 0, int $days = 0): Carbon
    {
        $base = ($existing?->current_period_end !== null && $existing->current_period_end->isFuture())
            ? $existing->current_period_end->copy()
            : Carbon::now();

        if ($months > 0) {
            $base = $base->addMonths($months);
        }
        if ($days > 0) {
            $base = $base->addDays($days);
        }

        return $base;
    }
}
