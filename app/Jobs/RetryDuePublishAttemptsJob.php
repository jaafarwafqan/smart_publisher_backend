<?php

namespace App\Jobs;

use App\Models\PostPublicationAttempt;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sprint 3: the scheduled sweeper that turns 'retry_scheduled' attempts
 * back into work once their next_attempt_at is due. Deliberately does NOT
 * claim anything itself — it just re-dispatches PublishPostJob, and the
 * actual atomic claim (AttemptStateMachine::claimDueRetry(), inside
 * PublishEngineService::publish()) is what decides whether that dispatch
 * does anything. If two sweeper runs (or a sweeper run racing a still-live
 * worker) both pick up the same due attempt, only one dispatch will
 * actually proceed past the claim — the other harmlessly no-ops.
 *
 * This is the same system-level cross-tenant scan pattern as
 * ProcessScheduledPostsJob/SyncPostMetricsCommand: bypass OrganizationScope
 * for the initial due-attempt query (nothing is set yet), then run every
 * per-attempt operation inside TenantContext::run() for that attempt's own
 * organization.
 */
class RetryDuePublishAttemptsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        PostPublicationAttempt::withoutGlobalScope(OrganizationScope::class)
            ->where('status', 'retry_scheduled')
            ->where('next_attempt_at', '<=', now())
            ->chunkById(100, function ($attempts): void {
                foreach ($attempts as $attempt) {
                    app(TenantContext::class)->run((int) $attempt->organization_id, function () use ($attempt): void {
                        PublishPostJob::dispatch(
                            $attempt->post_id,
                            $attempt->social_page_id,
                            $attempt->publish_batch_key,
                            (int) $attempt->organization_id,
                            $attempt->id,
                        );
                    });
                }
            });
    }
}
