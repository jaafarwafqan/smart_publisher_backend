<?php

namespace App\Jobs;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialPage;
use App\Services\NotificationService;
use App\Support\Publishing\PostStateMachine;
use App\Support\Publishing\PublicationBatchCoordinator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes one publication attempt. Current fan-outs always include an exact
 * persisted attempt id. The legacy bridge also materializes the complete
 * current target set before processing, so every post completion and user
 * notification is still decided exclusively by PublicationBatchCoordinator.
 */
class PublishPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public ?string $requestId = null;

    public ?string $correlationId = null;

    public ?string $traceId = null;

    /**
     * organizationId is captured explicitly at dispatch time: queue workers
     * have no ambient TenantContext to inherit from the dispatching request.
     */
    public function __construct(
        public int $postId,
        public int $socialPageId,
        public ?string $publishBatchKey = null,
        public int $organizationId = 0,
        public ?int $publicationAttemptId = null,
    ) {
        $this->onQueue((string) config('publishing.queue', 'publishing'));
        $this->requestId = request()->attributes->get('request_id');
        $this->correlationId = request()->attributes->get('correlation_id');
        $this->traceId = request()->attributes->get('trace_id');
    }

    /**
     * Approval is an audited delegation, rather than a live permission
     * snapshot. The one live authorization condition is that the author
     * remains an organization member when a worker executes the attempt.
     *
     * The optional coordinator preserves direct invocation compatibility for
     * focused tests while Laravel resolves it normally in a worker.
     */
    public function handle(PublishEngineService $engine, ?PublicationBatchCoordinator $batches = null): void
    {
        $batches ??= app(PublicationBatchCoordinator::class);

        app(TenantContext::class)->run($this->organizationId, function () use ($engine, $batches): void {
            $post = Post::query()->find($this->postId);

            if (! $post || $post->status !== 'publishing') {
                // The post was cancelled, completed by a sibling target, or
                // otherwise pulled out of the publishing pipeline after this
                // job was dispatched. Do not call a provider in that state.
                return;
            }

            $attempt = $this->expectedAttempt($post);
            if ($this->publicationAttemptId !== null && $attempt === null) {
                // Never manufacture a replacement attempt for a job which
                // was dispatched with an exact durable target id.
                return;
            }

            if ($attempt !== null) {
                $attempt = $this->ensureAttemptHasBatchKey($post, $attempt);
            }

            $socialPage = SocialPage::query()->with('socialAccount')->find($this->socialPageId);

            if (! $socialPage || ! $socialPage->socialAccount) {
                $this->failBatchPrecondition(
                    $post,
                    $attempt,
                    'The selected social page or connected account is no longer available.',
                    $batches,
                );

                return;
            }

            // Older queued jobs did not include a durable attempt id. Always
            // re-materialize every current target before processing their one
            // target: a prior legacy worker may have created only its own
            // row before this release introduced batch fan-out.
            if ($this->publicationAttemptId === null) {
                $attempt = $this->prepareLegacyBatchAttempt($post, $socialPage, $batches);
            }

            if ($attempt === null) {
                return;
            }

            if (! $post->user->isMemberOf($this->organizationId)) {
                $this->failBatchPrecondition(
                    $post,
                    $attempt,
                    'Post author is no longer a member of this organization.',
                    $batches,
                );

                return;
            }

            $engine->publishExistingAttempt($attempt, $post, $socialPage);

            // A target-level success, retry, or permanent failure is not a
            // post outcome. The coordinator sees the complete durable set and
            // performs the one allowed terminal post transition/notice.
            $this->completeBatch($post, $attempt, $batches);
        });
    }

    /**
     * Looks up a trusted exact-id dispatch, or (only for old payloads)
     * locates an already-materialized target in the post's batch.
     */
    private function expectedAttempt(Post $post): ?PostPublicationAttempt
    {
        if ($this->publicationAttemptId !== null) {
            $attempt = PostPublicationAttempt::query()->find($this->publicationAttemptId);

            if (
                $attempt === null
                || (int) $attempt->post_id !== $post->id
                || (int) $attempt->social_page_id !== $this->socialPageId
                || ($this->publishBatchKey !== null && $attempt->publish_batch_key !== $this->publishBatchKey)
            ) {
                return null;
            }

            return $attempt;
        }

        $batchKey = $this->publishBatchKey ?: $post->publish_batch_key;
        if ($batchKey === null) {
            return null;
        }

        return PostPublicationAttempt::query()
            ->where('post_id', $post->id)
            ->where('social_page_id', $this->socialPageId)
            ->where('publish_batch_key', $batchKey)
            ->first();
    }

    private function ensureAttemptHasBatchKey(Post $post, PostPublicationAttempt $attempt): PostPublicationAttempt
    {
        $batchKey = $this->ensurePostBatchKey($post);

        if ($attempt->publish_batch_key !== $batchKey) {
            $attempt->update(['publish_batch_key' => $batchKey]);
        }

        return $attempt;
    }

    private function ensurePostBatchKey(Post $post): string
    {
        if ($post->publish_batch_key !== null) {
            return $post->publish_batch_key;
        }

        $batchKey = (string) Str::uuid();
        app(PostStateMachine::class)->transition($post, 'publishing', [
            'publish_batch_key' => $batchKey,
        ]);

        return $batchKey;
    }

    private function prepareLegacyBatchAttempt(
        Post $post,
        SocialPage $socialPage,
        PublicationBatchCoordinator $batches,
    ): ?PostPublicationAttempt {
        $batchKey = $this->ensurePostBatchKey($post);
        $pages = $this->currentBatchTargets($post, $socialPage);
        $attempts = $batches->createPendingAttempts($post, $pages, $batchKey);

        return $attempts->firstWhere('social_page_id', $socialPage->id);
    }

    /**
     * @return Collection<int, SocialPage>
     */
    private function currentBatchTargets(Post $post, SocialPage $socialPage): Collection
    {
        $pages = $post->socialPages()
            ->with('socialAccount')
            ->where('can_publish', true)
            ->where('status', 'valid')
            ->get(['social_pages.id', 'social_pages.social_account_id', 'social_pages.kind']);

        if (! $pages->contains('id', $socialPage->id)) {
            $pages->push($socialPage);
        }

        return $pages;
    }

    private function failBatchPrecondition(
        Post $post,
        ?PostPublicationAttempt $attempt,
        string $errorMessage,
        PublicationBatchCoordinator $batches,
    ): void {
        if ($attempt === null || $attempt->publish_batch_key === null) {
            return;
        }

        $batches->failPendingAttempt($attempt, $errorMessage);
        $this->completeBatch($post, $attempt, $batches);
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

    /**
     * Safety net only: expected provider failures resolve inside
     * PublishEngineService. An escaping exception gets a DLQ record and, when
     * its attempt was materialized, becomes terminal before completion is
     * evaluated. It cannot fail the whole post while sibling targets remain.
     */
    public function failed(Throwable $exception): void
    {
        app(TenantContext::class)->run($this->organizationId, function () use ($exception): void {
            $post = Post::query()->find($this->postId);

            if (! $post) {
                return;
            }

            $errorMessage = 'Unexpected error: '.$exception->getMessage();
            $attempt = $this->expectedAttempt($post);

            app(PublishEngineService::class)->pushToDeadLetter(
                self::class,
                (string) config('publishing.queue', 'publishing'),
                'post',
                $post->id,
                [
                    'post_id' => $this->postId,
                    'social_page_id' => $this->socialPageId,
                    'publication_attempt_id' => $this->publicationAttemptId,
                    'publish_batch_key' => $attempt?->publish_batch_key ?: ($this->publishBatchKey ?: $post->publish_batch_key),
                ],
                $exception->getMessage(),
                $this->attempts(),
            );

            if ($attempt === null) {
                return;
            }

            $attempt = $this->ensureAttemptHasBatchKey($post, $attempt);
            $batches = app(PublicationBatchCoordinator::class);
            $batches->failAttemptAfterWorkerError($attempt, $errorMessage);
            $this->completeBatch($post, $attempt, $batches);
        });
    }
}
