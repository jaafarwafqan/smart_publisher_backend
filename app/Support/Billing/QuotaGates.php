<?php

namespace App\Support\Billing;

use LogicException;

/**
 * Product contract for every quota/feature gate enforced by the API.
 *
 * A plan may deliberately set a numeric gate to null (explicitly unlimited)
 * or a boolean gate to true/false, but it must still declare every key.
 * Keeping the complete gate list and the migration-safe fallback in one
 * place prevents a newly introduced gate from accidentally locking an
 * otherwise valid paid organization out — or, just as importantly, from
 * accidentally leaving it enabled somewhere nobody meant to grant it.
 */
final class QuotaGates
{
    public const TEAM_MEMBERS = 'max_team_members';

    public const SOCIAL_ACCOUNTS = 'max_social_accounts';

    public const SCHEDULED_POSTS_PER_MONTH = 'max_scheduled_posts_per_month';

    // 2026-08 feature-gates review: everything that actually distinguishes
    // this product commercially — approval workflows, an audit trail,
    // branches, full analytics — was free for every plan, including one
    // with zero subscription at all. A small organization had no reason to
    // ever upgrade.
    public const FEATURE_APPROVAL_WORKFLOW = 'feature_approval_workflow';

    public const FEATURE_AUDIT_LOG = 'feature_audit_log';

    public const FEATURE_BRANCHES = 'feature_branches';

    public const FEATURE_ADVANCED_ANALYTICS = 'feature_advanced_analytics';

    /**
     * Minimum service retained if a legacy active plan was created before a
     * newly-added numeric quota key. This is intentionally the documented
     * Free tier, not an implicit unlimited bypass and never a silent
     * zero-capacity lock.
     *
     * @var array<string, int>
     */
    private const FALLBACK_LIMITS = [
        self::TEAM_MEMBERS => 5,
        self::SOCIAL_ACCOUNTS => 3,
        self::SCHEDULED_POSTS_PER_MONTH => 30,
    ];

    /**
     * Same fail-closed-with-documented-fallback philosophy as
     * FALLBACK_LIMITS, applied to boolean feature gates: every one defaults
     * to false today. A legacy plan that predates one of these features
     * never silently gains it just because the key is missing from its
     * stored limits.
     *
     * Deliberately a method, not a `private const` — every value happens to
     * be false right now, and a compile-time constant's literal values get
     * folded by static analysis into the narrowest possible type,
     * (mis)reporting every reader of it as "never true" even though the
     * declared, intended contract is a genuine bool that a future gate
     * will set true. The @return annotation is what future code should rely
     * on, not today's literal values.
     *
     * @return array<string, bool>
     */
    private static function fallbackFeaturesMap(): array
    {
        return [
            self::FEATURE_APPROVAL_WORKFLOW => false,
            self::FEATURE_AUDIT_LOG => false,
            self::FEATURE_BRANCHES => false,
            self::FEATURE_ADVANCED_ANALYTICS => false,
        ];
    }

    /** @return array<string, int> */
    public static function fallbackLimits(): array
    {
        return self::FALLBACK_LIMITS;
    }

    /** @return array<string, bool> */
    public static function fallbackFeatures(): array
    {
        return self::fallbackFeaturesMap();
    }

    /** @return array<string, int|bool> every fallback, numeric and boolean */
    public static function fallbackAll(): array
    {
        return [...self::FALLBACK_LIMITS, ...self::fallbackFeaturesMap()];
    }

    /** @return list<string> numeric quota keys only */
    public static function limitKeys(): array
    {
        return array_keys(self::FALLBACK_LIMITS);
    }

    /** @return list<string> boolean feature keys only */
    public static function featureKeys(): array
    {
        return array_keys(self::fallbackFeaturesMap());
    }

    /** @return list<string> every declared key, numeric and boolean */
    public static function all(): array
    {
        return [...self::limitKeys(), ...self::featureKeys()];
    }

    /** @return int The explicit minimum capacity for a known missing numeric gate. */
    public static function fallbackFor(string $key): int
    {
        if (! array_key_exists($key, self::FALLBACK_LIMITS)) {
            throw new LogicException("Unknown organization quota gate [{$key}]. Add it to ".self::class.' before enforcing it.');
        }

        return self::FALLBACK_LIMITS[$key];
    }

    /** @return bool The explicit default for a known missing boolean feature gate. */
    public static function fallbackFeatureFor(string $key): bool
    {
        $features = self::fallbackFeaturesMap();
        if (! array_key_exists($key, $features)) {
            throw new LogicException("Unknown organization feature gate [{$key}]. Add it to ".self::class.' before enforcing it.');
        }

        return $features[$key];
    }

    /** @return array<string, null|true> unlimited numeric capacity, every feature enabled */
    public static function unlimitedLimits(): array
    {
        return [
            ...array_fill_keys(self::limitKeys(), null),
            ...array_fill_keys(self::featureKeys(), true),
        ];
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
