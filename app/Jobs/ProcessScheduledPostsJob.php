<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\Scopes\OrganizationScope;
use App\Services\NotificationService;
use App\Support\Publishing\ClosedBetaPublishingGate;
use App\Support\Publishing\PostStateMachine;
use App\Support\Publishing\PublicationBatchCoordinator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProcessScheduledPostsJob implements ShouldQueue
{
    use Queueable;

    /**
     * This is a system-level fan-out job — it legitimately needs to see due
     * posts across every organization, not just one, so it explicitly
     * bypasses OrganizationScope for its own scan (the one sanctioned
     * exception; see OrganizationScope's docblock). Every per-post
     * operation afterward still runs inside TenantContext::run($post's own
     * organization_id), so nothing downstream (socialPages() relation
     * queries, the post update, PublishPostJob's own scoped work once it
     * runs later in a separate queue:work process) ever sees a leaked or
     * missing tenant context.
     */
    public function handle(): void
    {
        Post::withoutGlobalScope(OrganizationScope::class)
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->chunkById(100, function ($posts): void {
                foreach ($posts as $post) {
                    app(TenantContext::class)->run((int) $post->organization_id, function () use ($post): void {
                        try {
                            // Check every selected target, including a page
                            // that is temporarily not publishable. A scheduled
                            // production post must not later revive an
                            // unsupported provider/kind into a queue job.
                            $targetPages = $post->socialPages()
                                ->with('socialAccount')
                                ->get(['social_pages.id', 'social_pages.social_account_id', 'social_pages.kind']);

                            app(ClosedBetaPublishingGate::class)->assertPagesAllowed($targetPages);
                        } catch (ValidationException) {
                            $this->failUnsupportedClosedBetaTargets($post);

                            return;
                        }

                        // Atomic claim: a conditional UPDATE with an
                        // affected-rows check, not read-then-write. Two
                        // overlapping scheduler ticks (withoutOverlapping()
                        // normally prevents this, but this guard doesn't
                        // depend on that config staying in place) can both
                        // select the same due post in their initial query
                        // above — only one may actually claim it here.
                        $attempts = DB::transaction(function () use ($post) {
                            $batchKey = $post->publish_batch_key ?: (string) Str::uuid();
                            $claimed = Post::withoutGlobalScope(OrganizationScope::class)
                                ->where('id', $post->id)
                                ->where('status', 'scheduled')
                                ->update(['status' => 'publishing', 'publish_batch_key' => $batchKey]);

                            if ($claimed !== 1) {
                                return null;
                            }

                            $post->status = 'publishing';
                            $post->publish_batch_key = $batchKey;

                            $pages = $post->socialPages()
                                ->with('socialAccount')
                                ->where('can_publish', true)
                                ->where('status', 'valid')
                                ->get(['social_pages.id', 'social_pages.social_account_id', 'social_pages.kind']);

                            if ($pages->isEmpty()) {
                                app(PostStateMachine::class)->transition($post, 'failed', [
                                    'failed_at' => now(),
                                    'last_error' => 'No usable target pages selected for this post.',
                                ]);
                                app(NotificationService::class)->publicationFailed($post);

                                return collect();
                            }

                            return app(PublicationBatchCoordinator::class)->createPendingAttempts($post, $pages, $batchKey);
                        });

                        if ($attempts === null) {
                            return;
                        }

                        foreach ($attempts as $attempt) {
                            PublishPostJob::dispatch(
                                $post->id,
                                (int) $attempt->social_page_id,
                                $attempt->publish_batch_key,
                                (int) $post->organization_id,
                                (int) $attempt->id,
                            );
                        }
                    });
                }
            });
    }

    /**
     * A row scheduled before a target restriction was introduced has no HTTP
     * caller to receive a 422. Settle it once without creating an attempt or
     * a worker job, rather than leaving it due forever for every scheduler
     * tick to rediscover.
     */
    private function failUnsupportedClosedBetaTargets(Post $post): void
    {
        DB::transaction(function () use ($post): void {
            $lockedPost = Post::withoutGlobalScope(OrganizationScope::class)
                ->lockForUpdate()
                ->find($post->id);

            if ($lockedPost === null || $lockedPost->status !== 'scheduled') {
                return;
            }

            app(PostStateMachine::class)->transition($lockedPost, 'failed', [
                'failed_at' => now(),
                'last_error' => 'One or more selected social targets are not enabled for the production closed beta.',
            ]);

            app(NotificationService::class)->publicationFailed($lockedPost);
        });
    }
}
