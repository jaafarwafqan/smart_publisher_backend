<?php

namespace App\Infrastructure\ExternalServices\Publishing;

use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Models\DeadLetterJob;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialPage;
use App\Services\ContextLogger;
use App\Support\LiteMarkdown;
use App\Support\Publishing\AttemptStateMachine;
use App\Support\Publishing\ClosedBetaPublishingGate;
use App\Support\Publishing\PublishErrorClassifier;
use App\Support\Publishing\RetryBackoffCalculator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Sprint 3 (Publishing Core) rewrite. Each publish attempt is now a single,
 * self-contained unit of work with an explicit lifecycle, all enforced
 * through AttemptStateMachine — never a raw ->update(['status' => ...]):
 *
 *   pending -> processing -> success
 *                         -> retry_scheduled -> processing (retried later
 *                            by RetryDuePublishAttemptsJob)
 *                         -> failed -> dead_letter
 *
 * The atomic claim (pending -> processing, or retry_scheduled -> processing
 * for the sweeper) is a conditional UPDATE with an affected-rows check —
 * never read-then-write — so two workers racing for the same attempt can
 * never both proceed to call the provider.
 */
class PublishEngineService
{
    /**
     * How long a 'processing' claim is trusted before being considered
     * abandoned by a crashed worker — see AttemptStateMachine::reclaimStale().
     */
    private int $staleClaimSeconds;

    private readonly RetryBackoffCalculator $backoffCalculator;

    public function __construct(
        private readonly SocialOAuthManager $oauthManager,
        private readonly ClosedBetaPublishingGate $closedBetaPublishingGate,
        private readonly AttemptStateMachine $stateMachine = new AttemptStateMachine,
        private readonly PublishErrorClassifier $classifier = new PublishErrorClassifier,
        ?RetryBackoffCalculator $backoffCalculator = null,
    ) {
        $this->staleClaimSeconds = (int) config('publishing.claim_stale_after_seconds', 300);
        // Not constructor-promoted with a default like the others above:
        // the base/max seconds come from config, and a default parameter
        // value must be a compile-time constant, so it can't call config()
        // directly.
        $this->backoffCalculator = $backoffCalculator ?? new RetryBackoffCalculator(
            (int) config('publishing.backoff_base_seconds', 10),
            (int) config('publishing.backoff_max_seconds', 900),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function publish(Post $post, SocialPage $socialPage, ?string $batchKey = null): array
    {
        $this->closedBetaPublishingGate->assertPageAllowed($socialPage);

        $idempotencyKey = $this->idempotencyKey($post->id, $socialPage->id, $batchKey);
        $attempt = $this->findOrCreateAttempt(
            $post,
            $socialPage->socialAccount,
            $socialPage,
            $idempotencyKey,
            $batchKey ?? $post->publish_batch_key,
        );

        return $this->processAttempt($attempt, $post, $socialPage);
    }

    /**
     * Creates the durable, exact attempt that a batch coordinator dispatches.
     * The attempt exists before any job is queued so completion can account
     * for every target even while workers finish in a different order.
     */
    public function createAttemptForBatch(Post $post, SocialPage $socialPage, string $batchKey): PostPublicationAttempt
    {
        $this->closedBetaPublishingGate->assertPageAllowed($socialPage);

        if ($socialPage->socialAccount === null) {
            throw new RuntimeException('Cannot create a publication attempt without a social account.');
        }

        return $this->findOrCreateAttempt(
            $post,
            $socialPage->socialAccount,
            $socialPage,
            $this->idempotencyKey($post->id, $socialPage->id, $batchKey),
            $batchKey,
        );
    }

    /**
     * Process a previously-created attempt instead of deriving a fresh
     * idempotency key. RetryDuePublishAttemptsJob must use this path: the
     * original publication batch key is intentionally part of the hash and
     * cannot be reconstructed from a queued retry after the fact.
     *
     * @return array<string, mixed>
     */
    public function publishExistingAttempt(PostPublicationAttempt $attempt, Post $post, SocialPage $socialPage): array
    {
        if ($attempt->post_id !== $post->id || $attempt->social_page_id !== $socialPage->id) {
            throw new RuntimeException('Publication attempt does not match the requested post target.');
        }

        $this->closedBetaPublishingGate->assertPageAllowed($socialPage);

        return $this->processAttempt($attempt, $post, $socialPage);
    }

    /**
     * The manual DLQ-retry path deliberately does NOT go through
     * findOrCreateAttempt()/idempotencyKey() — it re-opens and re-processes
     * this EXACT attempt row (same idempotency_key, no new row created), so
     * a retry can never produce a second attempt racing the original for
     * the same publish. AttemptStateMachine::transition() enforces that only
     * a 'dead_letter' attempt can move to 'pending' here; the atomic
     * pending -> processing claim inside processAttempt() then guarantees
     * only one caller (even two concurrent manual-retry clicks somehow both
     * reaching this far) ever actually calls the provider.
     *
     * @return array<string, mixed>
     */
    public function retryDeadLetteredAttempt(PostPublicationAttempt $attempt): array
    {
        $post = Post::query()->findOrFail($attempt->post_id);
        $socialPage = SocialPage::query()->with('socialAccount')->findOrFail($attempt->social_page_id);

        $this->closedBetaPublishingGate->assertPageAllowed($socialPage);

        $this->stateMachine->transition($attempt, 'pending', [
            'claimed_at' => null,
            'claimed_by' => null,
        ]);

        return $this->processAttempt($attempt, $post, $socialPage);
    }

    /**
     * @return array<string, mixed>
     */
    private function processAttempt(PostPublicationAttempt $attempt, Post $post, SocialPage $socialPage): array
    {
        $socialAccount = $socialPage->socialAccount;
        $organizationId = app(TenantContext::class)->get();
        $idempotencyKey = $attempt->idempotency_key;

        $claimResult = $this->claimAttempt($attempt, $post, $socialPage);
        if ($claimResult !== null) {
            return $claimResult;
        }

        $workerScope = $this->workerScope($socialAccount->provider, $organizationId);
        if ($this->isCircuitOpen($workerScope)) {
            // Release the claim rather than burning an attempt on a circuit
            // we already know is open — retry shortly once it might have
            // recovered, without this counting against the real retry budget.
            $this->stateMachine->transition($attempt, 'retry_scheduled', [
                'next_attempt_at' => now()->addMinutes(1),
                'error_message' => 'Circuit breaker open for '.$workerScope['label'],
                'error_classification' => PublishErrorClassifier::RETRYABLE,
            ]);

            return [
                'status' => 'retry_scheduled',
                'attempt_id' => $attempt->id,
                'idempotency_key' => $idempotencyKey,
                'reason' => 'circuit_open',
            ];
        }

        if (! $socialAccount->access_token) {
            return $this->finalizeFailure(
                $attempt,
                $post,
                $socialPage,
                'Social account access token is missing.',
                PublishErrorClassifier::NON_RETRYABLE,
            );
        }

        try {
            $providerResponse = $this->callProvider($post, $socialAccount, $socialPage);

            $this->stateMachine->transition($attempt, 'success', [
                'provider_response' => json_encode($providerResponse),
                'provider_request_id' => $providerResponse['provider_post_id'] ?? null,
                'error_message' => null,
                'error_classification' => null,
                'processed_at' => now(),
            ]);

            $socialAccount->update(['last_published_at' => now()]);
            $this->clearCircuit($workerScope);

            ContextLogger::info('publishing.attempt.success', [
                'post_id' => $post->id,
                'social_page_id' => $socialPage->id,
                'provider' => $socialAccount->provider,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'status' => 'success',
                'attempt_id' => $attempt->id,
                'idempotency_key' => $idempotencyKey,
                'provider_response' => $providerResponse,
            ];
        } catch (Throwable $e) {
            return $this->handlePublishFailure($attempt, $post, $socialPage, $e, $workerScope);
        }
    }

    /**
     * @return array<string, mixed>|null null means "proceed, claim succeeded"
     */
    private function claimAttempt(PostPublicationAttempt $attempt, Post $post, SocialPage $socialPage): ?array
    {
        if ($attempt->status === 'success') {
            return [
                'status' => 'duplicate_ignored',
                'attempt_id' => $attempt->id,
                'idempotency_key' => $attempt->idempotency_key,
            ];
        }

        if (in_array($attempt->status, ['failed', 'dead_letter'], true)) {
            // A permanently-resolved attempt is never auto-retried by the
            // normal publish path — only an explicit manual DLQ retry
            // (which transitions dead_letter -> pending itself) re-opens it.
            return [
                'status' => $attempt->status,
                'attempt_id' => $attempt->id,
                'idempotency_key' => $attempt->idempotency_key,
            ];
        }

        if ($attempt->status === 'pending') {
            if ($this->stateMachine->claim($attempt, $this->workerId())) {
                return null;
            }

            return [
                'status' => 'concurrent_attempt_in_progress',
                'attempt_id' => $attempt->id,
                'idempotency_key' => $attempt->idempotency_key,
            ];
        }

        if ($attempt->status === 'retry_scheduled') {
            if ($attempt->next_attempt_at !== null && $attempt->next_attempt_at->isFuture()) {
                return [
                    'status' => 'not_yet_due',
                    'attempt_id' => $attempt->id,
                    'idempotency_key' => $attempt->idempotency_key,
                    'next_attempt_at' => $attempt->next_attempt_at,
                ];
            }

            if ($this->stateMachine->claimDueRetry($attempt, $this->workerId())) {
                return null;
            }

            return [
                'status' => 'concurrent_attempt_in_progress',
                'attempt_id' => $attempt->id,
                'idempotency_key' => $attempt->idempotency_key,
            ];
        }

        // status === 'processing': either genuinely in flight elsewhere, or
        // its previous claimant crashed. If we can reclaim it as stale, we
        // deliberately do NOT proceed to call the provider again — neither
        // Facebook's nor Telegram's public post-creation APIs accept a
        // client idempotency token, so there is no safe way to tell whether
        // the crashed worker's provider call already succeeded. Silently
        // retrying risks a duplicate publish; finalizing it as ambiguous
        // (dead_letter, distinct classification, visible error message)
        // avoids that while still keeping it in front of a human via the
        // manual DLQ retry rather than losing it silently.
        if ($this->stateMachine->reclaimStale($attempt, $this->workerId(), $this->staleClaimSeconds)) {
            return $this->finalizeFailure(
                $attempt,
                $post,
                $socialPage,
                'Worker claiming this attempt did not report a result before the stale-claim window elapsed. The provider call may have already succeeded — verify the target page/channel before using manual DLQ retry.',
                PublishErrorClassifier::AMBIGUOUS_AFTER_CRASH,
            );
        }

        return [
            'status' => 'concurrent_attempt_in_progress',
            'attempt_id' => $attempt->id,
            'idempotency_key' => $attempt->idempotency_key,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handlePublishFailure(
        PostPublicationAttempt $attempt,
        Post $post,
        SocialPage $socialPage,
        Throwable $e,
        array $workerScope,
    ): array {
        $classification = $this->classifier->classify($e);
        $this->recordCircuitFailure($workerScope, $classification);

        if ($classification === PublishErrorClassifier::NON_RETRYABLE) {
            return $this->finalizeFailure($attempt, $post, $socialPage, $e->getMessage(), $classification);
        }

        $maxAttempts = $classification === PublishErrorClassifier::UNKNOWN
            ? (int) config('publishing.unknown_error_max_retries', 1)
            : (int) config('publishing.max_retries', 3);

        if ($attempt->attempt_number >= $maxAttempts) {
            return $this->finalizeFailure(
                $attempt,
                $post,
                $socialPage,
                $e->getMessage(),
                $classification,
                true,
            );
        }

        $retryAfter = $this->classifier->retryAfterSeconds($e);
        $delaySeconds = $retryAfter ?? $this->backoffCalculator->secondsFor($attempt->attempt_number);

        $this->stateMachine->transition($attempt, 'retry_scheduled', [
            'next_attempt_at' => now()->addSeconds($delaySeconds),
            'error_message' => $e->getMessage(),
            'error_classification' => $classification,
        ]);

        ContextLogger::warning('publishing.attempt.retry_scheduled', [
            'post_id' => $post->id,
            'social_page_id' => $socialPage->id,
            'provider' => $socialPage->socialAccount->provider,
            'attempt_number' => $attempt->attempt_number,
            'classification' => $classification,
            'delay_seconds' => $delaySeconds,
            'respected_retry_after' => $retryAfter !== null,
        ]);

        return [
            'status' => 'retry_scheduled',
            'attempt_id' => $attempt->id,
            'idempotency_key' => $attempt->idempotency_key,
            'classification' => $classification,
            'next_attempt_at' => $attempt->fresh()->next_attempt_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finalizeFailure(
        PostPublicationAttempt $attempt,
        Post $post,
        SocialPage $socialPage,
        string $errorMessage,
        string $classification,
        bool $retryExhausted = false,
    ): array {
        $this->stateMachine->transition($attempt, 'failed', [
            'error_message' => $errorMessage,
            'error_classification' => $classification,
            'processed_at' => now(),
        ]);

        ContextLogger::warning('publishing.attempt.failed', [
            'post_id' => $post->id,
            'social_page_id' => $socialPage->id,
            'provider' => $socialPage->socialAccount->provider,
            'attempt_number' => $attempt->attempt_number,
            'classification' => $classification,
            'error' => $errorMessage,
        ]);

        $this->pushToDeadLetter(
            PostPublicationAttempt::class,
            (string) config('publishing.queue', 'publishing'),
            'post_publication_attempt',
            $attempt->id,
            [
                'post_id' => $post->id,
                'social_page_id' => $socialPage->id,
                'idempotency_key' => $attempt->idempotency_key,
                'classification' => $classification,
            ],
            $errorMessage,
            $attempt->attempt_number,
        );

        $this->stateMachine->transition($attempt, 'dead_letter');

        return [
            'status' => 'failed',
            'attempt_id' => $attempt->id,
            'idempotency_key' => $attempt->idempotency_key,
            'classification' => $classification,
            'error_message' => $errorMessage,
            'retry_exhausted' => $retryExhausted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function callProvider(Post $post, $socialAccount, SocialPage $socialPage): array
    {
        // A per-platform override (composer's "different text for this
        // platform") wins over the shared body; either way, the **bold**/
        // _italic_ markers only Telegram can really render become actual
        // HTML there and are cleanly stripped everywhere else — never sent
        // as literal asterisks to a platform that can't display them.
        $platformContent = (array) ($post->meta['platform_content'] ?? []);
        $rawContent = $platformContent[$socialAccount->provider] ?? $post->content;
        $content = $socialAccount->provider === 'telegram'
            ? LiteMarkdown::toTelegramHtml($rawContent)
            : LiteMarkdown::toPlainText($rawContent);

        return $this->oauthManager->publishPost($socialAccount->provider, $socialAccount->access_token, [
            'provider_account_id' => $socialPage->page_id,
            'page_id' => $socialPage->page_id,
            // Facebook only (null for Telegram, which has no per-page
            // credential) — see FacebookOAuthProvider::publishPost()'s own
            // docblock for why publishing needs this over the account-level
            // token passed as this call's own 2nd argument above.
            'page_access_token' => $socialPage->access_token,
            'title' => $post->title,
            'content' => $content,
            'post_meta' => $post->meta ?? [],
            'attachments' => $post->mediaAttachments->map(fn ($attachment) => [
                'disk' => $attachment->disk,
                'path' => $attachment->path,
                'mime_type' => $attachment->mime_type,
                'type' => $attachment->type,
                'original_name' => $attachment->meta['original_name'] ?? basename($attachment->path),
            ])->all(),
        ]);
    }

    private function findOrCreateAttempt(
        Post $post,
        $socialAccount,
        SocialPage $socialPage,
        string $idempotencyKey,
        ?string $batchKey,
    ): PostPublicationAttempt {
        $existing = PostPublicationAttempt::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $attemptNumber = (int) PostPublicationAttempt::query()
            ->where('post_id', $post->id)
            ->where('social_page_id', $socialPage->id)
            ->max('attempt_number');

        try {
            return PostPublicationAttempt::query()->create([
                'post_id' => $post->id,
                'publish_batch_key' => $batchKey,
                'social_account_id' => $socialAccount->id,
                'social_page_id' => $socialPage->id,
                'idempotency_key' => $idempotencyKey,
                'attempt_number' => $attemptNumber + 1,
                'status' => 'pending',
            ]);
        } catch (QueryException) {
            // A concurrent caller won the race to insert this exact
            // idempotency_key between our existence check and this create()
            // — the unique constraint is the real guarantee, this catch just
            // hands back whichever row actually landed instead of throwing.
            return PostPublicationAttempt::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }

    /**
     * Retained for the manual DLQ-retry path and for legacy call sites that
     * still report a failure without going through publish() itself (e.g. a
     * dispatch-time validation failure with no attempt row at all yet).
     */
    public function markFailure(Post $post, SocialPage $socialPage, string $errorMessage, int $attempts, ?string $batchKey = null): void
    {
        $socialAccount = $socialPage->socialAccount;
        $idempotencyKey = $this->idempotencyKey($post->id, $socialPage->id, $batchKey);

        $attempt = PostPublicationAttempt::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($attempt && $this->stateMachine->canTransition($attempt->status, 'failed')) {
            $this->stateMachine->transition($attempt, 'failed', [
                'attempt_number' => $attempts,
                'error_message' => $errorMessage,
                'processed_at' => now(),
            ]);
        } elseif (! $attempt) {
            PostPublicationAttempt::query()->create([
                'post_id' => $post->id,
                'publish_batch_key' => $batchKey ?? $post->publish_batch_key,
                'social_account_id' => $socialAccount->id,
                'social_page_id' => $socialPage->id,
                'idempotency_key' => $idempotencyKey,
                'attempt_number' => $attempts,
                'status' => 'failed',
                'error_message' => $errorMessage,
                'processed_at' => now(),
            ]);
        }

        ContextLogger::warning('publishing.attempt.failed', [
            'post_id' => $post->id,
            'social_page_id' => $socialPage->id,
            'provider' => $socialAccount->provider,
            'attempts' => $attempts,
            'error' => $errorMessage,
        ]);

        // No Throwable is available on this legacy/manual-DLQ path to
        // classify — defaults to UNKNOWN (still counts toward the shared
        // provider circuit), the same conservative treatment classify()
        // gives an unrecognized error shape elsewhere.
        $this->recordCircuitFailure(
            $this->workerScope($socialAccount->provider, (int) $post->organization_id),
            PublishErrorClassifier::UNKNOWN,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function pushToDeadLetter(string $jobClass, ?string $queueName, ?string $referenceType, ?int $referenceId, array $payload, string $errorMessage, int $attempts): void
    {
        DeadLetterJob::query()->create([
            'queue_name' => $queueName,
            'job_class' => $jobClass,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            // Never persist tokens/secrets into the dead-letter payload —
            // this is a plain scalar payload (ids/keys/classification), not
            // the raw provider request/response, precisely so a leaked or
            // widely-viewed DLQ row can't leak a credential.
            'payload' => json_encode($payload),
            'error_message' => $errorMessage,
            'attempts' => $attempts,
            'failed_at' => now(),
        ]);

        ContextLogger::error('publishing.dead_letter.created', [
            'job_class' => $jobClass,
            'queue_name' => $queueName,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'attempts' => $attempts,
        ]);
    }

    public function clearProviderFailures(string $provider, ?int $organizationId = null): void
    {
        Cache::forget($this->providerFailureCounterKey($provider));
        Cache::forget($this->providerCircuitKey($provider));

        if ($organizationId !== null) {
            Cache::forget($this->orgFailureCounterKey($provider, $organizationId));
            Cache::forget($this->orgCircuitKey($provider, $organizationId));
        }
    }

    /**
     * @return array{provider: string, organizationId: int, label: string}
     */
    private function workerScope(string $provider, int $organizationId): array
    {
        return ['provider' => $provider, 'organizationId' => $organizationId, 'label' => "{$provider} (org #{$organizationId})"];
    }

    /**
     * Two independent circuits, either of which blocks this specific
     * (provider, organization) combination: the shared provider-wide one
     * (trips on genuine widespread trouble, high threshold) and this
     * organization's own (trips fast on its own local failures — a
     * revoked token or account-specific rate limit never blocks any other
     * tenant's publishing on the same provider).
     */
    private function isCircuitOpen(array $scope): bool
    {
        return (bool) Cache::get($this->providerCircuitKey($scope['provider']), false)
            || (bool) Cache::get($this->orgCircuitKey($scope['provider'], $scope['organizationId']), false);
    }

    /**
     * A NON_RETRYABLE failure (revoked token, missing permission, deleted
     * page, rejected content — see PublishErrorClassifier) is a fact about
     * THIS org's account, not the provider platform. Counting it toward
     * the shared provider-wide circuit meant one tenant's bad token could
     * contribute to tripping every other tenant's publishing on the same
     * provider. The org-scoped circuit below still trips on it regardless
     * of classification — that's the correct, fast, tenant-local response.
     */
    private function recordCircuitFailure(array $scope, string $classification): void
    {
        if ($classification !== PublishErrorClassifier::NON_RETRYABLE) {
            $this->incrementCounter(
                $this->providerFailureCounterKey($scope['provider']),
                $this->providerCircuitKey($scope['provider']),
                (int) config('publishing.circuit_breaker_threshold', 5),
                (int) config('publishing.circuit_breaker_ttl_minutes', 10),
            );
        }

        $this->incrementCounter(
            $this->orgFailureCounterKey($scope['provider'], $scope['organizationId']),
            $this->orgCircuitKey($scope['provider'], $scope['organizationId']),
            (int) config('publishing.org_circuit_breaker_threshold', 3),
            (int) config('publishing.org_circuit_breaker_ttl_minutes', 10),
        );
    }

    private function clearCircuit(array $scope): void
    {
        $this->clearProviderFailures($scope['provider'], $scope['organizationId']);
    }

    /**
     * Sprint 2 (API Hardening): was a read-then-write race —
     * `Cache::get() + 1` then `Cache::put()` as two separate operations, so
     * two workers recording a failure for the same provider/org at nearly
     * the same moment could both read the same starting value and one
     * increment would be silently lost, undercounting real failures and
     * delaying (or in a sustained-concurrency scenario, indefinitely
     * postponing) the circuit actually tripping at the configured
     * threshold. `Cache::add()` (atomic "set if absent") followed by
     * `Cache::increment()` (atomic on every driver this app actually uses —
     * database, redis, memcached, array) makes the count itself immune to
     * that race.
     *
     * Deliberate, disclosed behavior change: the previous `Cache::put()`
     * refreshed the TTL to a fresh $ttlMinutes on every single failure — a
     * sliding window that (bug aside) could never actually expire under
     * sustained-but-spaced-out failures. There is no atomic
     * "increment-and-refresh-TTL" primitive available across this app's
     * actual cache drivers without reintroducing the exact race being
     * fixed here (re-reading the incremented value to write it back would
     * risk clobbering a concurrent increment). This is now a fixed window
     * anchored at the first failure — standard circuit-breaker semantics,
     * and arguably more correct than an infinitely-extendable one, but a
     * real semantic change from before, not just a race fix.
     */
    private function incrementCounter(string $counterKey, string $circuitKey, int $threshold, int $ttlMinutes): void
    {
        Cache::add($counterKey, 0, now()->addMinutes($ttlMinutes));
        $failures = Cache::increment($counterKey);

        if ($failures !== false && $failures >= $threshold) {
            Cache::put($circuitKey, true, now()->addMinutes($ttlMinutes));
        }
    }

    private function providerFailureCounterKey(string $provider): string
    {
        return 'publishing:provider:'.$provider.':failures';
    }

    private function providerCircuitKey(string $provider): string
    {
        return 'publishing:provider:'.$provider.':circuit-open';
    }

    private function orgFailureCounterKey(string $provider, int $organizationId): string
    {
        return 'publishing:provider:'.$provider.':org:'.$organizationId.':failures';
    }

    private function orgCircuitKey(string $provider, int $organizationId): string
    {
        return 'publishing:provider:'.$provider.':org:'.$organizationId.':circuit-open';
    }

    /**
     * post_id/social_page_id are already globally-unique primary keys, so a
     * collision across organizations was never actually possible here — but
     * the Sprint 1 checklist explicitly asks for organization_id woven into
     * idempotency keys (defense-in-depth, and it makes the key legible when
     * debugging/auditing across tenants), so it's included regardless.
     * $batchKey is the "publication_intent" the user asked for — a fresh
     * publish_batch_key is generated every time schedule()/publishNow() is
     * called (see PostController), so a genuinely new, intentional publish
     * request never collides with a prior one's idempotency key.
     */
    private function idempotencyKey(int $postId, int $socialPageId, ?string $batchKey = null): string
    {
        $organizationId = app(TenantContext::class)->get();

        return hash('sha256', 'org-'.$organizationId.'-post-'.$postId.'-page-'.$socialPageId.'-batch-'.($batchKey ?: 'default'));
    }

    private function workerId(): string
    {
        return (gethostname() ?: 'unknown').':'.getmypid().':'.bin2hex(random_bytes(4));
    }
}
