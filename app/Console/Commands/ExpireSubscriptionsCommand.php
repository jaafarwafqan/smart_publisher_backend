<?php

namespace App\Console\Commands;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Services\ContextLogger;
use App\Services\NotificationService;
use App\Support\Billing\DefaultPlans;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Prepaid-billing model (2026-08-21): none of the Iraqi gateways this
 * product integrates with support recurring subscriptions — a paid period
 * simply ends. Without this command, OrganizationSubscription::status never
 * changes on its own once current_period_end passes, so nothing would ever
 * mark a lapsed subscription 'expired' or downgrade it to Free; isActiveOrTrialing()
 * relies on this running daily to keep that column meaningful.
 *
 * Runs in two independent passes: (1) send 7-day and 1-day advance warnings
 * (see NotificationService::subscriptionExpiringSoon() for its own
 * once-per-day dedupe), (2) actually expire anything already past its
 * current_period_end. A subscription that crosses BOTH thresholds on the
 * same run (e.g. current_period_end was exactly "now" already) is simply
 * expired — an expiry warning for a subscription being expired in the same
 * breath would be noise, not useful.
 */
class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'billing:expire-subscriptions';

    protected $description = 'Warn organizations whose prepaid period is about to lapse, and downgrade any that already have.';

    public function handle(NotificationService $notifications): int
    {
        $this->sendExpiryWarnings($notifications);
        $this->expireLapsedSubscriptions();

        return Command::SUCCESS;
    }

    private function sendExpiryWarnings(NotificationService $notifications): void
    {
        foreach ([7, 1] as $daysRemaining) {
            $windowStart = now()->addDays($daysRemaining)->startOfDay();
            $windowEnd = now()->addDays($daysRemaining)->endOfDay();

            OrganizationSubscription::query()
                ->whereIn('status', ['active', 'trialing'])
                ->whereBetween('current_period_end', [$windowStart, $windowEnd])
                ->with('organization')
                ->get()
                ->each(function (OrganizationSubscription $subscription) use ($notifications, $daysRemaining): void {
                    $organization = $subscription->organization;
                    if (! $organization) {
                        return;
                    }

                    app(TenantContext::class)->run($organization->id, fn () => $notifications->subscriptionExpiringSoon($organization, $daysRemaining));
                });
        }
    }

    private function expireLapsedSubscriptions(): void
    {
        $freePlan = Plan::query()->firstOrCreate(['slug' => DefaultPlans::FREE_SLUG], DefaultPlans::free());

        OrganizationSubscription::query()
            ->whereIn('status', ['active', 'trialing'])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', now())
            ->with('organization')
            ->get()
            ->each(function (OrganizationSubscription $subscription) use ($freePlan): void {
                $oldPlanId = $subscription->plan_id;
                $oldPeriodEnd = $subscription->current_period_end;

                $subscription->update([
                    'status' => 'expired',
                    'plan_id' => $freePlan->id,
                    'current_period_end' => null,
                ]);

                PlatformAuditLog::query()->create([
                    'actor_user_id' => null,
                    'organization_id' => $subscription->organization_id,
                    'action' => 'billing.subscription_expired',
                    'auditable_type' => OrganizationSubscription::class,
                    'auditable_id' => $subscription->id,
                    'old_values' => ['plan_id' => $oldPlanId, 'current_period_end' => $oldPeriodEnd?->toIso8601String()],
                    'new_values' => ['plan_id' => $freePlan->id, 'status' => 'expired'],
                ]);

                ContextLogger::info('billing.subscription_expired', [
                    'organization_id' => $subscription->organization_id,
                    'previous_plan_id' => $oldPlanId,
                ]);
            });
    }
}
