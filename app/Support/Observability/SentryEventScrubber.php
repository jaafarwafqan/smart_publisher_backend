<?php

namespace App\Support\Observability;

use Sentry\Event;
use Sentry\EventHint;

/**
 * config/sentry.php sets send_default_pii=false, which keeps Sentry from
 * attaching request cookies/IP/user data on its own — but that default does
 * nothing about values OUR code puts into an event's request body, extra
 * data, or exception context: OAuth access/refresh tokens on a
 * SocialAccount, a Telegram webhook_secret, a user's two_factor_secret. Any
 * of those can legitimately end up in a validated request payload or a job's
 * exception context and would otherwise be shipped to Sentry verbatim.
 *
 * Registered as config('sentry.before_send') — a static method reference
 * rather than an object instance, since config/sentry.php must survive
 * `php artisan config:cache` (var_export can't reconstruct an arbitrary
 * object, only a class-string callable).
 */
final class SentryEventScrubber
{
    private const REDACTED = '[redacted]';

    /**
     * Matched case-insensitively against array keys anywhere in the
     * scrubbed structures — substring match on purpose, so
     * 'new_access_token' or 'facebook_access_token' are caught too, not
     * just an exact 'access_token' key.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'access_token',
        'refresh_token',
        'webhook_secret',
        'two_factor_secret',
        'recovery_code',
        'password',
        'client_secret',
        'authorization',
    ];

    public static function scrub(Event $event, ?EventHint $hint): Event
    {
        $request = $event->getRequest();
        if ($request !== []) {
            $event->setRequest(self::scrubArray($request));
        }

        $extra = $event->getExtra();
        if ($extra !== []) {
            $event->setExtra(self::scrubArray($extra));
        }

        foreach ($event->getContexts() as $name => $data) {
            if ($data !== []) {
                $event->setContext($name, self::scrubArray($data));
            }
        }

        return $event;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function scrubArray(array $data): array
    {
        $scrubbed = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $scrubbed[$key] = self::REDACTED;

                continue;
            }
            $scrubbed[$key] = is_array($value) ? self::scrubArray($value) : $value;
        }

        return $scrubbed;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
