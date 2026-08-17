<?php

namespace App\Services;

use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

class DashboardCacheService
{
    public const ANALYTICS_TTL_SECONDS = 60;

    public const CALENDAR_TTL_SECONDS = 30;

    public const SETTINGS_TTL_SECONDS = 300;

    /**
     * Previously a single global key with no user/organization component at
     * all — every tenant's analytics dashboard shared one cache entry,
     * meaning organization B's request could be served organization A's
     * cached analytics. Now keyed by the active TenantContext.
     */
    public function analyticsKey(): string
    {
        return 'dashboard:analytics:v1:org:'.app(TenantContext::class)->get();
    }

    /**
     * Code-quality review (2026-08-17): a distinct key from analyticsKey()
     * above — GET /analytics and GET /analytics/dashboard return different
     * payload shapes (summary counts vs. top-posts/engagement totals), so
     * sharing one cache key would serve one endpoint the other's cached
     * response.
     */
    public function dashboardKey(): string
    {
        return 'dashboard:analytics-dashboard:v1:org:'.app(TenantContext::class)->get();
    }

    public function postAnalyticsKey(int $postId): string
    {
        return 'dashboard:analytics-post:v1:org:'.app(TenantContext::class)->get().':post:'.$postId;
    }

    public function calendarKey(int $userId, bool $canViewAll): string
    {
        // A role downgrade must take effect immediately. Without this
        // visibility discriminator, a manager's organization-wide calendar
        // could remain cached for the same user after they become an editor.
        return 'dashboard:calendar:v1:org:'.app(TenantContext::class)->get()
            .':visibility:'.($canViewAll ? 'all' : 'own')
            .':user:'.$userId;
    }

    public function settingsKey(int $userId): string
    {
        return 'dashboard:settings:v1:org:'.app(TenantContext::class)->get().':user:'.$userId;
    }

    /**
     * @param  callable():array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function rememberAnalytics(callable $resolver): array
    {
        return $this->remember($this->analyticsKey(), self::ANALYTICS_TTL_SECONDS, $resolver);
    }

    /**
     * @param  callable():array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function rememberCalendar(int $userId, bool $canViewAll, callable $resolver): array
    {
        return $this->remember($this->calendarKey($userId, $canViewAll), self::CALENDAR_TTL_SECONDS, $resolver);
    }

    /**
     * @param  callable():array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function rememberSettings(int $userId, callable $resolver): array
    {
        return $this->remember($this->settingsKey($userId), self::SETTINGS_TTL_SECONDS, $resolver);
    }

    /**
     * @param  callable():array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function rememberDashboard(callable $resolver): array
    {
        return $this->remember($this->dashboardKey(), self::ANALYTICS_TTL_SECONDS, $resolver);
    }

    /**
     * Code-quality review (2026-08-17): deliberately NOT extended to
     * AnalyticsController::bulk() — that endpoint's cache key would have to
     * encode an arbitrary, caller-supplied set of post ids. On a
     * database-backed cache store (this app's real default; see remember()
     * below) that means one new, likely-never-reused row per distinct id
     * combination requested — a poor hit rate purchased at the cost of an
     * unbounded, un-evictable-by-invalidateDashboard() cache table. The same
     * 60s TTL as every other analytics cache entry already bounds
     * per-post staleness without that trade-off.
     *
     * @param  callable():array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function rememberPostAnalytics(int $postId, callable $resolver): array
    {
        return $this->remember($this->postAnalyticsKey($postId), self::ANALYTICS_TTL_SECONDS, $resolver);
    }

    public function invalidateDashboard(?int $userId = null): void
    {
        Cache::forget($this->analyticsKey());
        Cache::forget($this->dashboardKey());

        if ($userId !== null) {
            Cache::forget($this->calendarKey($userId, true));
            Cache::forget($this->calendarKey($userId, false));
            Cache::forget($this->settingsKey($userId));
        }
    }

    /**
     * @param  callable():array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    private function remember(string $key, int $ttlSeconds, callable $resolver): array
    {
        $store = Cache::store(config('cache.default'));

        // Repository::tags() exists on every store (so method_exists() always
        // returned true here), but it only actually works when the underlying
        // store extends TaggableStore (array/Redis/Memcached). The 'database'
        // driver (this app's actual default per .env) doesn't, and throws
        // BadMethodCallException the moment ->remember() runs — this made
        // GET /api/v1/analytics 500 on every request against the real default
        // cache driver, invisible in tests only because phpunit.xml overrides
        // CACHE_STORE to a taggable driver.
        if ($store->getStore() instanceof TaggableStore) {
            return Cache::tags(['dashboard'])->remember($key, now()->addSeconds($ttlSeconds), $resolver);
        }

        return Cache::remember($key, now()->addSeconds($ttlSeconds), $resolver);
    }
}
