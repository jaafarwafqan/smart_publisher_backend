<?php

namespace App\Exceptions\Publishing;

use RuntimeException;
use Throwable;

/**
 * Replaces the bare RuntimeException every SocialOAuthProviderContract
 * implementation used to throw on a failed publishPost() call — that threw
 * away the actual HTTP status code and Retry-After header, making it
 * impossible for PublishErrorClassifier to tell a revoked token (401, never
 * worth retrying) from a rate limit (429, retry after the provider's own
 * cooldown) from a transient 5xx (retry with backoff).
 */
class ProviderPublishException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
