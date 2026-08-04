<?php

namespace App\Support\Publishing;

use App\Exceptions\Publishing\ProviderPublishException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Sprint 3: every publish failure gets sorted into exactly one bucket,
 * which drives what PublishEngineService does next — this is the single
 * place that decision is made, so retry policy can't drift between call
 * sites.
 *
 *  - retryable: the failure is very likely transient (network blip,
 *    provider having a bad moment, explicit rate limit) — worth another
 *    attempt with backoff.
 *  - non_retryable: the failure is a fact about the world that a retry
 *    can't fix (revoked token, missing permission, deleted page, rejected
 *    content) — goes straight to dead_letter without wasting attempts.
 *  - unknown: an error shape PublishErrorClassifier doesn't recognize.
 *    Treated cautiously — a bounded, small number of retries rather than
 *    either extreme (assuming it's always safe to hammer, or giving up
 *    after zero attempts).
 */
class PublishErrorClassifier
{
    public const RETRYABLE = 'retryable';

    public const NON_RETRYABLE = 'non_retryable';

    public const UNKNOWN = 'unknown';

    /**
     * Not a Throwable-driven classification like the other three — used by
     * PublishEngineService when it reclaims a 'processing' attempt whose
     * claim went stale (its worker never reported a result, almost
     * certainly because it crashed). Neither Facebook's nor Telegram's
     * public post-creation APIs accept a client idempotency token, so there
     * is no way to safely ask "did that in-flight call already succeed?"
     * before deciding whether to retry. Auto-retrying risks a duplicate
     * publish; the attempt is finalized as ambiguous instead, so a human can
     * check the actual page/channel before using the manual DLQ retry.
     */
    public const AMBIGUOUS_AFTER_CRASH = 'ambiguous_after_crash';

    /**
     * HTTP status codes that are near-certainly permanent for a publish
     * call: the credential/permission/target is wrong, not the network.
     */
    private const NON_RETRYABLE_STATUSES = [400, 401, 403, 404, 410, 422];

    /**
     * 429 (explicit rate limit) and 5xx (provider-side trouble) are the
     * textbook transient cases.
     */
    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public function classify(Throwable $e): string
    {
        if ($e instanceof ConnectionException) {
            return self::RETRYABLE;
        }

        if ($e instanceof ProviderPublishException) {
            return $this->classifyByStatus($e->httpStatus, $e->getMessage());
        }

        return self::UNKNOWN;
    }

    public function retryAfterSeconds(Throwable $e): ?int
    {
        if ($e instanceof ProviderPublishException && $e->retryAfterSeconds !== null && $e->retryAfterSeconds > 0) {
            return $e->retryAfterSeconds;
        }

        return null;
    }

    private function classifyByStatus(?int $status, string $message): string
    {
        if ($status === null) {
            return self::UNKNOWN;
        }

        // A 400 is usually "bad request" (permanent — malformed content,
        // rejected by the platform's own moderation) but Facebook/Telegram
        // both also use it for expired/invalid-token errors, which is the
        // same bucket anyway (non_retryable), so no special case is needed
        // — every status in NON_RETRYABLE_STATUSES already means "retrying
        // this exact call will never succeed."
        if (in_array($status, self::NON_RETRYABLE_STATUSES, true)) {
            return self::NON_RETRYABLE;
        }

        if (in_array($status, self::RETRYABLE_STATUSES, true)) {
            return self::RETRYABLE;
        }

        return self::UNKNOWN;
    }
}
