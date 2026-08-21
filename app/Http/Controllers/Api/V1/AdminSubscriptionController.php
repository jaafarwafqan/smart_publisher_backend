<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ExtendOrganizationSubscriptionRequest;
use App\Http\Requests\Platform\GrantOrganizationSubscriptionRequest;
use App\Http\Requests\Platform\GrantOrganizationSubscriptionTrialRequest;
use App\Http\Requests\Platform\RevertOrganizationSubscriptionRequest;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Support\Billing\BillingPeriodGrantService;
use App\Support\Billing\DefaultPlans;
use App\Support\Platform\PlatformAuditLogger;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * Prepaid-billing model (2026-08-21) — manual super-admin subscription
 * management. In this model, a manual grant/extension and a real paid
 * gateway renewal are literally the same operation; both go through
 * BillingPeriodGrantService rather than each hand-rolling their own date
 * math. Every action here requires a documented reason (see each
 * FormRequest's rules()) and is written to platform_audit_logs with the
 * subscription's before/after snapshot — a free grant with no documented
 * reason is an audit gap.
 */
class AdminSubscriptionController extends Controller
{
    public function grant(
        GrantOrganizationSubscriptionRequest $request,
        Organization $organization,
        BillingPeriodGrantService $grants,
        PlatformAuditLogger $audit,
    ): JsonResponse {
        $validated = $request->validated();
        $plan = Plan::query()->whereKey($validated['plan_id'])->where('is_active', true)->firstOrFail();
        $before = $this->snapshot($organization);

        $subscription = $grants->grantPlan(
            $organization,
            $plan,
            (int) $validated['months'],
            $request->user(),
            $validated['reason'],
        );

        $audit->record(
            $request,
            $request->user(),
            'organization.subscription_granted',
            OrganizationSubscription::class,
            $subscription->id,
            $before,
            $this->snapshot($organization) + ['reason' => $validated['reason']],
            $organization->id,
        );

        return response()->json(['message' => 'Subscription granted.', 'data' => $this->payload($subscription)], 201);
    }

    public function extend(
        ExtendOrganizationSubscriptionRequest $request,
        Organization $organization,
        BillingPeriodGrantService $grants,
        PlatformAuditLogger $audit,
    ): JsonResponse {
        $validated = $request->validated();
        $before = $this->snapshot($organization);

        try {
            $subscription = $grants->extendPeriod(
                $organization,
                (int) ($validated['months'] ?? 0),
                (int) ($validated['days'] ?? 0),
                $request->user(),
                $validated['reason'],
            );
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $audit->record(
            $request,
            $request->user(),
            'organization.subscription_extended',
            OrganizationSubscription::class,
            $subscription->id,
            $before,
            $this->snapshot($organization) + ['reason' => $validated['reason']],
            $organization->id,
        );

        return response()->json(['message' => 'Subscription extended.', 'data' => $this->payload($subscription)]);
    }

    /**
     * Applies Free's limits immediately — the very next OrganizationEntitlements
     * read sees Free, since it re-queries organization_subscriptions on
     * every call rather than caching plan state.
     */
    public function revert(
        RevertOrganizationSubscriptionRequest $request,
        Organization $organization,
        BillingPeriodGrantService $grants,
        PlatformAuditLogger $audit,
    ): JsonResponse {
        $freePlan = Plan::query()->firstOrCreate(['slug' => DefaultPlans::FREE_SLUG], DefaultPlans::free());
        $reason = $request->validated()['reason'];
        $before = $this->snapshot($organization);

        $subscription = $grants->revertToFree($organization, $freePlan, $request->user(), $reason);

        $audit->record(
            $request,
            $request->user(),
            'organization.subscription_reverted_to_free',
            OrganizationSubscription::class,
            $subscription->id,
            $before,
            $this->snapshot($organization) + ['reason' => $reason],
            $organization->id,
        );

        return response()->json(['message' => 'Organization reverted to the Free plan.', 'data' => $this->payload($subscription)]);
    }

    public function trial(
        GrantOrganizationSubscriptionTrialRequest $request,
        Organization $organization,
        BillingPeriodGrantService $grants,
        PlatformAuditLogger $audit,
    ): JsonResponse {
        $freePlan = Plan::query()->firstOrCreate(['slug' => DefaultPlans::FREE_SLUG], DefaultPlans::free());
        $validated = $request->validated();
        $before = $this->snapshot($organization);

        $subscription = $grants->grantTrial(
            $organization,
            (int) $validated['days'],
            $request->user(),
            $validated['reason'],
            $freePlan,
        );

        $audit->record(
            $request,
            $request->user(),
            'organization.subscription_trial_granted',
            OrganizationSubscription::class,
            $subscription->id,
            $before,
            $this->snapshot($organization) + ['reason' => $validated['reason']],
            $organization->id,
        );

        return response()->json(['message' => 'Trial granted.', 'data' => $this->payload($subscription)], 201);
    }

    /** @return array<string, mixed> */
    private function snapshot(Organization $organization): array
    {
        $subscription = $organization->subscription()->first();

        return [
            'plan_id' => $subscription?->plan_id,
            'status' => $subscription?->status,
            'current_period_end' => $subscription?->current_period_end?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(OrganizationSubscription $subscription): array
    {
        $subscription->refresh();

        return [
            'organization_id' => $subscription->organization_id,
            'plan_id' => $subscription->plan_id,
            'status' => $subscription->status,
            'current_period_start' => $subscription->current_period_start?->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'provider_subscription_id' => $subscription->provider_subscription_id,
            'granted_by_user_id' => $subscription->granted_by_user_id,
            'granted_reason' => $subscription->granted_reason,
        ];
    }
}
