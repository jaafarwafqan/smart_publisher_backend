<?php

namespace App\Jobs;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\Scopes\OrganizationScope;
use App\Models\SocialPage;
use App\Services\NotificationService;
use App\Support\Publishing\PublicationBatchCoordinator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The watchdog for crashed workers. AttemptStateMachine::reclaimStale() and
 * PublishEngineService::claimAttempt()'s handling of a stale 'processing'
 * claim have existed since Sprint 3, but both are only ever reached
 * reactively — via a fresh call to publish()/publishExistingAttempt() for
 * that exact attempt. Nothing periodic ever made that call on its own, so a
 * worker that died mid-publish left its attempt (and the post, stuck in
 * 'publishing') abandoned forever unless a human happened to trigger the
 * same idempotency key again. This job is the missing periodic sweep:
 * find every 'processing' attempt whose claim is older than the configured
 * stale window, and finalize it through the exact same reclaim path
 * PublishEngineService already implements — never a raw status write.
 *
 * Same system-level cross-tenant scan pattern as
 * ProcessScheduledPostsJob/RetryDuePublishAttemptsJob: bypass
 * OrganizationScope for the initial stale-attempt query (nothing is set
 * yet), then run every per-attempt operation inside TenantContext::run()
 * for that attempt's own organization.
 */
class ReclaimStalePublishAttemptsJob implements ShouldQueue
{
    use Queueable;

    public function handle(PublishEngineService $engine): void
    {
        $staleSeconds = (int) config('publishing.claim_stale_after_seconds', 300);

        PostPublicationAttempt::withoutGlobalScope(OrganizationScope::class)
            ->where('status', 'processing')
            ->where('claimed_at', '<=', now()->subSeconds($staleSeconds))
            ->chunkById(100, function ($attempts) use ($engine): void {
                foreach ($attempts as $attempt) {
                    app(TenantContext::class)->run((int) $attempt->organization_id, function () use ($attempt, $engine): void {
                        $this->reclaim($attempt, $engine);
                    });
                }
            });
    }

    private function reclaim(PostPublicationAttempt $attempt, PublishEngineService $engine): void
    {
        $post = Post::query()->find($attempt->post_id);
        $socialPage = SocialPage::query()->with('socialAccount')->find($attempt->social_page_id);

        if ($post === null || $socialPage === null || $socialPage->socialAccount === null) {
            // The post/page/account was deleted out from under an abandoned
            // claim. Nothing left to finalize toward — a future manual DLQ
            // retry against this exact attempt would hit the same missing
            // dependency and fail closed there instead.
            return;
        }

        // publishExistingAttempt() re-runs claimAttempt() internally, which
        // is exactly where reclaimStale() lives — if the claim is still
        // genuinely stale (nothing else reclaimed/settled it since our
        // query above), this finalizes it to dead_letter with
        // AMBIGUOUS_AFTER_CRASH and never calls the provider again.
        $engine->publishExistingAttempt($attempt, $post, $socialPage);

        $this->completeBatch($post, $attempt->fresh());
    }

    /**
     * Mirrors PublishPostJob::completeBatch() — duplicated rather than
     * extracted into a shared helper, matching the existing project
     * convention (RetryDuePublishAttemptsJob also re-dispatches through
     * PublishPostJob rather than sharing completion logic directly).
     */
    private function completeBatch(Post $post, PostPublicationAttempt $attempt): void
    {
        $batchKey = $attempt->publish_batch_key;
        if ($batchKey === null) {
            return;
        }

        $completion = app(PublicationBatchCoordinator::class)->completeIfSettled($post, $batchKey);
        if ($completion === null) {
            return;
        }

        if ($completion['outcome'] === 'published') {
            app(NotificationService::class)->publicationSucceeded($completion['post']);

            return;
        }

        if ($completion['outcome'] === 'partial_success') {
            app(NotificationService::class)->publicationPartiallySucceeded($completion['post']);

            return;
        }

        if ($completion['retry_exhausted']) {
            app(NotificationService::class)->retryExhausted($completion['post'], $completion['failed_attempt_id']);

            return;
        }

        app(NotificationService::class)->publicationFailed($completion['post']);
    }
}
