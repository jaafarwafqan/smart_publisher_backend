<?php

namespace App\Jobs;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Services\NotificationService;
use App\Support\Publishing\PostStateMachine;
use App\Support\Publishing\PublicationBatchCoordinator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Re-processes one exact dead-lettered attempt. It always returns through the
 * post-level batch coordinator, so a manual retry cannot emit a target-level
 * success or failure notification.
 */
class RetryDeadLetteredAttemptJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $attemptId,
        public int $organizationId,
    ) {
        $this->onQueue((string) config('publishing.queue', 'publishing'));
    }

    public function handle(PublishEngineService $engine, ?PublicationBatchCoordinator $batches = null): void
    {
        $batches ??= app(PublicationBatchCoordinator::class);

        app(TenantContext::class)->run($this->organizationId, function () use ($engine, $batches): void {
            $attempt = PostPublicationAttempt::query()->find($this->attemptId);

            if (! $attempt || $attempt->status !== 'dead_letter') {
                return;
            }

            $post = Post::query()->find($attempt->post_id);

            // A failed post is the sole valid entry point for a manual retry.
            // A mismatched non-null key is a stale DLQ row and must not revive
            // a newer publishing intent.
            if (
                ! $post
                || $post->status !== 'failed'
                || (
                    $post->publish_batch_key !== null
                    && $attempt->publish_batch_key !== null
                    && $post->publish_batch_key !== $attempt->publish_batch_key
                )
                || ! $post->user->isMemberOf($this->organizationId)
            ) {
                return;
            }

            $batchKey = $post->publish_batch_key ?: $attempt->publish_batch_key ?: (string) Str::uuid();

            if ($post->publish_batch_key !== $batchKey) {
                app(PostStateMachine::class)->transition($post, 'failed', [
                    'publish_batch_key' => $batchKey,
                ]);
            }

            if ($attempt->publish_batch_key !== $batchKey) {
                $attempt->update(['publish_batch_key' => $batchKey]);
            }

            app(PostStateMachine::class)->transition($post, 'publishing');
            $engine->retryDeadLetteredAttempt($attempt);

            $this->completeBatch($post, $attempt, $batches);
        });
    }

    private function completeBatch(
        Post $post,
        PostPublicationAttempt $attempt,
        PublicationBatchCoordinator $batches,
    ): void {
        $batchKey = $attempt->publish_batch_key;
        if ($batchKey === null) {
            return;
        }

        $completion = $batches->completeIfSettled($post, $batchKey);
        if ($completion === null) {
            return;
        }

        if ($completion['outcome'] === 'published') {
            app(NotificationService::class)->publicationSucceeded($completion['post']);

            return;
        }

        if ($completion['retry_exhausted']) {
            app(NotificationService::class)->retryExhausted($completion['post'], $completion['failed_attempt_id']);

            return;
        }

        app(NotificationService::class)->publicationFailed($completion['post']);
    }
}
