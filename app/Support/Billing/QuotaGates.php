<?php

namespace App\Support\Billing;

use LogicException;

/**
 * Product contract for every quota enforced by the API.
 *
 * A plan may deliberately set a gate to null (explicitly unlimited), but it
 * must still declare the key. Keeping the complete gate list and the
 * migration-safe fallback in one place prevents a newly introduced quota
 * from accidentally locking an otherwise valid paid organization.
 */
final class QuotaGates
{
    public const TEAM_MEMBERS = 'max_team_members';

    public const SOCIAL_ACCOUNTS = 'max_social_accounts';

    public const SCHEDULED_POSTS_PER_MONTH = 'max_scheduled_posts_per_month';

    /**
     * Minimum service retained if a legacy active plan was created before a
     * newly-added quota key. This is intentionally the documented Free tier,
     * not an implicit unlimited bypass and never a silent zero-capacity lock.
     *
     * @var array<string, int>
     */
    private const FALLBACK_LIMITS = [
        self::TEAM_MEMBERS => 5,
        self::SOCIAL_ACCOUNTS => 3,
        self::SCHEDULED_POSTS_PER_MONTH => 30,
    ];

    /** @return array<string, int> */
    public static function fallbackLimits(): array
    {
        return self::FALLBACK_LIMITS;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::FALLBACK_LIMITS);
    }

    /** @return int The explicit minimum capacity for a known missing gate. */
    public static function fallbackFor(string $key): int
    {
        if (! array_key_exists($key, self::FALLBACK_LIMITS)) {
            throw new LogicException("Unknown organization quota gate [{$key}]. Add it to ".self::class.' before enforcing it.');
        }

        return self::FALLBACK_LIMITS[$key];
    }

    /** @return array<string, null> */
    public static function unlimitedLimits(): array
    {
        return array_fill_keys(self::all(), null);
    }

    /**
     * @param  array<string, mixed>|null  $limits
     * @return list<string>
     */
    public static function missingFrom(?array $limits): array
    {
        $limits ??= [];

        return array_values(array_filter(
            self::all(),
            static fn (string $key): bool => ! array_key_exists($key, $limits),
        ));
    }
}
