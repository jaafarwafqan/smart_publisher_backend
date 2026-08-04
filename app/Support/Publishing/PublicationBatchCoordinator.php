<?php

namespace App\Support\Publishing;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Coordinates the post-level outcome for one explicit publishing batch.
 * Every selected target receives a persisted attempt before dispatch; the
 * post is then settled exactly once, after every attempt reaches a terminal
 * state. This deliberately keeps target execution in PublishEngineService
 * and puts only the cross-target decision here.
 */
final class PublicationBatchCoordinator
{
    /** @var list<string> */
    private const TERMINAL_ATTEMPT_STATUSES = ['success', 'failed', 'dead_letter'];

    public function __construct(
        private readonly PublishEngineService $engine,
        private readonly AttemptStateMachine $attemptStateMachine,
        private readonly PostStateMachine $postStateMachine,
        private readonly ClosedBetaPublishingGate $closedBetaPublishingGate,
    ) {}

    /**
     * @param  Collection<int, SocialPage>  $pages
     * @return Collection<int, PostPublicationAttempt>
     */
    public function createPendingAttempts(Post $post, Collection $pages, string $batchKey): Collection
    {
        if ($pages->isEmpty()) {
            throw new LogicException('A publication batch requires at least one target page.');
        }

        // This is the last synchronous boundary before durable attempts are
        // written. Every caller (HTTP, scheduler, and legacy-job bridge)
        // therefore fails closed before it can enqueue an unsupported target.
        $this->closedBetaPublishingGate->assertPagesAllowed($pages);

        return DB::transaction(function () use ($post, $pages, $batchKey): Collection {
            $attempts = $pages->map(
                fn (SocialPage $page): PostPublicationAttempt => $this->engine->createAttemptForBatch($post, $page, $batchKey),
            );

            if ($attempts->count() !== $pages->count()) {
                throw new LogicException('The publication batch did not create an attempt for every target page.');
            }

            return $attempts;
        });
    }

    /**
     * Finalizes a post only when this exact batch has no pending, processing,
     * or retry-scheduled attempts left. A batch that settles with a mix of
     * successes and failures becomes 'partial_success' rather than
     * 'failed' — the previous logic (any non-success attempt at all ->
     * 'failed') hid the fact that some targets really did publish, which
     * matters most exactly when it's easy to miss: a 3-page batch where 2
     * succeeded looked identical to a total failure.
     *
     * @return array{post: Post, outcome: 'published'|'partial_success'|'failed', failed_attempt_id: int|null, retry_exhausted: bool}|null
     */
    public function completeIfSettled(Post $post, string $batchKey): ?array
    {
        return DB::transaction(function () use ($post, $batchKey): ?array {
            $lockedPost = Post::query()->lockForUpdate()->find($post->id);

            if (
                $lockedPost === null
                || $lockedPost->status !== 'publishing'
                || $lockedPost->publish_batch_key !== $batchKey
            ) {
                return null;
            }

            $attempts = PostPublicationAttempt::query()
                ->where('post_id', $lockedPost->id)
                ->where('publish_batch_key', $batchKey)
                ->get();

            if ($attempts->isEmpty() || $attempts->contains(
                fn (PostPublicationAttempt $attempt): bool => ! in_array($attempt->status, self::TERMINAL_ATTEMPT_STATUSES, true),
            )) {
                return null;
            }

            /** @var PostPublicationAttempt|null $failedAttempt */
            $failedAttempt = $attempts->first(
                fn (PostPublicationAttempt $attempt): bool => $attempt->status !== 'success',
            );

            if ($failedAttempt !== null) {
                $successCount = $attempts->where('status', 'success')->count();
                $outcome = $successCount > 0 ? 'partial_success' : 'failed';

                $this->postStateMachine->transition($lockedPost, $outcome, [
                    'failed_at' => now(),
                    'last_error' => $failedAttempt->error_message ?? 'Publishing failed for one or more targets.',
                ]);

                return [
                    'post' => $lockedPost,
                    'outcome' => $outcome,
                    'failed_attempt_id' => (int) $failedAttempt->id,
                    'retry_exhausted' => $this->retryBudgetWasExhausted($failedAttempt),
                ];
            }

            $this->postStateMachine->transition($lockedPost, 'published', [
                'published_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ]);

            return [
                'post' => $lockedPost,
                'outcome' => 'published',
                'failed_attempt_id' => null,
                'retry_exhausted' => false,
            ];
        });
    }

    /**
     * Turns a pre-created attempt into a terminal failure when a job discovers
     * a non-provider precondition failure, such as the author no longer being
     * a member of the organization. It still uses AttemptStateMachine so the
     * attempt lifecycle remains auditable.
     */
    public function failPendingAttempt(PostPublicationAttempt $attempt, string $errorMessage): void
    {
        DB::transaction(function () use ($attempt, $errorMessage): void {
            $lockedAttempt = PostPublicationAttempt::query()->lockForUpdate()->find($attempt->id);

            if (
                $lockedAttempt === null
                || in_array($lockedAttempt->status, self::TERMINAL_ATTEMPT_STATUSES, true)
                || ! in_array($lockedAttempt->status, ['pending', 'retry_scheduled'], true)
            ) {
                return;
            }

            if ($lockedAttempt->status === 'pending') {
                if (! $this->attemptStateMachine->claim($lockedAttempt, 'batch-coordinator')) {
                    return;
                }
            } else {
                // A page/account deletion is permanent even if the provider
                // retry was not due yet. Move only its deadline, then use the
                // state machine's guarded retry claim rather than writing a
                // lifecycle status directly.
                $lockedAttempt->update(['next_attempt_at' => now()]);

                if (! $this->attemptStateMachine->claimDueRetry($lockedAttempt, 'batch-coordinator')) {
                    return;
                }
            }

            $lockedAttempt->refresh();
            $this->attemptStateMachine->transition($lockedAttempt, 'failed', [
                'error_message' => $errorMessage,
                'error_classification' => PublishErrorClassifier::NON_RETRYABLE,
                'processed_at' => now(),
            ]);
            $this->attemptStateMachine->transition($lockedAttempt, 'dead_letter');
        });
    }

    /**
     * Records an unexpected queue-worker failure against the exact attempt
     * that worker had been assigned. Unlike failPendingAttempt(), this may
     * also close a processing row because the worker which claimed it is the
     * one reporting the failure.
     */
    public function failAttemptAfterWorkerError(PostPublicationAttempt $attempt, string $errorMessage): void
    {
        DB::transaction(function () use ($attempt, $errorMessage): void {
            $lockedAttempt = PostPublicationAttempt::query()->lockForUpdate()->find($attempt->id);

            if ($lockedAttempt === null || in_array($lockedAttempt->status, self::TERMINAL_ATTEMPT_STATUSES, true)) {
                return;
            }

            if ($lockedAttempt->status === 'pending') {
                if (! $this->attemptStateMachine->claim($lockedAttempt, 'batch-coordinator')) {
                    return;
                }

                $lockedAttempt->refresh();
            }

            if ($lockedAttempt->status !== 'processing') {
                return;
            }

            $this->attemptStateMachine->transition($lockedAttempt, 'failed', [
                'error_message' => $errorMessage,
                'error_classification' => PublishErrorClassifier::UNKNOWN,
                'processed_at' => now(),
            ]);
            $this->attemptStateMachine->transition($lockedAttempt, 'dead_letter');
        });
    }

    private function retryBudgetWasExhausted(PostPublicationAttempt $attempt): bool
    {
        return match ($attempt->error_classification) {
            PublishErrorClassifier::RETRYABLE => $attempt->attempt_number >= (int) config('publishing.max_retries', 3),
            PublishErrorClassifier::UNKNOWN => $attempt->attempt_number >= (int) config('publishing.unknown_error_max_retries', 1),
            default => false,
        };
    }
}
