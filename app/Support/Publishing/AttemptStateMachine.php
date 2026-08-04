<?php

namespace App\Support\Publishing;

use App\Exceptions\Publishing\IllegalStateTransitionException;
use App\Models\PostPublicationAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 3: the ONE place PostPublicationAttempt.status is allowed to
 * change — every caller (PublishEngineService, PublishPostJob,
 * RetryDuePublishAttemptsJob, the manual DLQ-retry endpoint) must go
 * through transition()/claim(), never a raw ->update(['status' => ...]).
 * That's what "prevent illegal transitions centrally, not scattered across
 * controllers/jobs" means in practice.
 *
 * States: pending (an attempt has been recorded but not yet claimed by a
 * worker — equivalent to "queued"), processing (claimed, provider call in
 * flight), success (terminal), retry_scheduled (failed but eligible for
 * another try — see next_attempt_at), failed (retries exhausted or a
 * non-retryable error — about to become dead_letter), dead_letter
 * (terminal until a manual retry re-queues it).
 */
class AttemptStateMachine
{
    private const TRANSITIONS = [
        'pending' => ['processing', 'cancelled'],
        'processing' => ['success', 'retry_scheduled', 'failed'],
        'retry_scheduled' => ['processing'],
        'failed' => ['dead_letter'],
        'dead_letter' => ['pending'],
        'success' => [],
        // Only reachable from 'pending' — PostController::cancel() only
        // ever cancels an attempt that has not yet been claimed by a
        // worker (see its row-locked all-attempts-still-pending check). A
        // 'processing'/'retry_scheduled' attempt may have already reached
        // the provider; there is no safe way to un-publish that, so it is
        // deliberately left to resolve normally instead of being silently
        // discarded here.
        'cancelled' => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $attributes  extra columns to set alongside the status change
     */
    public function transition(PostPublicationAttempt $attempt, string $to, array $attributes = []): void
    {
        if (! $this->canTransition($attempt->status, $to)) {
            throw new IllegalStateTransitionException(
                "Cannot transition publication attempt #{$attempt->id} from '{$attempt->status}' to '{$to}'."
            );
        }

        $attempt->update(array_merge(['status' => $to], $attributes));
    }

    /**
     * Atomic claim: exactly one caller can move an attempt from
     * pending -> processing, no matter how many workers race for it. This
     * is a conditional UPDATE with an affected-rows check — never a
     * read-then-write pair, which is the actual bug class this guards
     * against (two workers both reading status='pending' before either
     * writes).
     */
    public function claim(PostPublicationAttempt $attempt, string $workerId): bool
    {
        $affected = PostPublicationAttempt::query()
            ->where('id', $attempt->id)
            ->where('status', 'pending')
            ->whereNull('claimed_at')
            ->update([
                'status' => 'processing',
                'claimed_at' => now(),
                'claimed_by' => $workerId,
            ]);

        if ($affected === 1) {
            $attempt->refresh();

            return true;
        }

        return false;
    }

    /**
     * A 'processing' attempt whose claim is older than $staleAfterSeconds
     * almost certainly means the worker that claimed it crashed or was
     * killed before resolving it — otherwise it would have transitioned to
     * success/retry_scheduled/failed by now. Reclaiming lets it be retried
     * instead of sitting stuck forever. The WHERE clause re-checks
     * claimed_at against the exact value we last read (an optimistic lock),
     * so two callers racing to reclaim the same stale attempt still can't
     * both win.
     */
    public function reclaimStale(PostPublicationAttempt $attempt, string $workerId, int $staleAfterSeconds): bool
    {
        if ($attempt->status !== 'processing' || $attempt->claimed_at === null) {
            return false;
        }

        if ($attempt->claimed_at->gt(now()->subSeconds($staleAfterSeconds))) {
            return false;
        }

        $affected = PostPublicationAttempt::query()
            ->where('id', $attempt->id)
            ->where('status', 'processing')
            ->where('claimed_at', $attempt->claimed_at)
            ->update([
                'claimed_at' => now(),
                'claimed_by' => $workerId,
            ]);

        if ($affected === 1) {
            $attempt->refresh();

            return true;
        }

        return false;
    }

    /**
     * Atomic claim for the retry sweeper: only picks up attempts that are
     * actually due, and only one sweeper instance can claim a given one.
     *
     * Increments attempt_number here — this is the ONE place a retry
     * actually starts executing again. Previously attempt_number was only
     * ever set once at row creation and never advanced through the
     * retry_scheduled -> processing cycle, so
     * PublishEngineService::handlePublishFailure()'s
     * `attempt_number >= max_retries` exhaustion check compared the same
     * value every time and could never become true through real retries —
     * a persistently-retryable failure (e.g. a provider outage) would
     * retry forever instead of ever reaching dead_letter.
     */
    public function claimDueRetry(PostPublicationAttempt $attempt, string $workerId): bool
    {
        $affected = PostPublicationAttempt::query()
            ->where('id', $attempt->id)
            ->where('status', 'retry_scheduled')
            ->where('next_attempt_at', '<=', now())
            ->update([
                'status' => 'processing',
                'claimed_at' => now(),
                'claimed_by' => $workerId,
                'next_attempt_at' => null,
                'attempt_number' => DB::raw('attempt_number + 1'),
            ]);

        if ($affected === 1) {
            $attempt->refresh();

            return true;
        }

        return false;
    }
}
