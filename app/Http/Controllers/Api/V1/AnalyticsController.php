<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnalyticsResource;
use App\Models\Post;
use App\Models\PostMetric;
use App\Models\PostPublicationAttempt;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Below this many real data points for a given platform or publish hour,
     * a "best" pick would just be reading noise — report null instead of a
     * false-confidence guess.
     */
    private const MIN_SAMPLE_SIZE = 3;

    public function index(Request $request, DashboardCacheService $cache): JsonResponse
    {
        $this->authorizeAnalytics($request);

        $payload = $cache->rememberAnalytics(function (): array {
            $total = Post::query()->count();
            $published = Post::query()->where('status', 'published')->count();
            $failed = Post::query()->where('status', 'failed')->count();
            $scheduled = Post::query()->where('status', 'scheduled')->count();
            $draft = Post::query()->where('status', 'draft')->count();

            $engagementScore = $total > 0 ? round((($published * 1.0) - ($failed * 0.5)) / $total, 2) : 0.0;

            return [
                'total_posts' => $total,
                'published' => $published,
                'failed' => $failed,
                'scheduled' => $scheduled,
                'draft' => $draft,
                'engagement' => [
                    'score' => $engagementScore,
                    'trend' => $published >= $failed ? 'up' : 'down',
                ],
                'updated_at' => now()->toIso8601String(),
            ];
        });

        $resource = new AnalyticsResource($payload);

        return response()->json($resource->resolve());
    }

    /**
     * Code-quality review (2026-08-17): previously loaded every available
     * PostMetric row for the organization into PHP on every call
     * (uncached, unlike index() below) and did every sum/average/group-by
     * in application memory — a full-table-into-memory operation that grows
     * unbounded with the organization's post count. Every total here is now
     * computed by the database; PHP only ever sees at most 5 rows (top_posts)
     * plus two scalar aggregate rows (best_platform, best_publish_hour).
     */
    public function dashboard(Request $request, DashboardCacheService $cache): JsonResponse
    {
        $this->authorizeAnalytics($request);

        $payload = $cache->rememberDashboard(function (): array {
            $totals = PostMetric::query()
                ->where('is_available', true)
                ->selectRaw(
                    'COALESCE(SUM(reach), 0) as total_reach, '.
                    'COALESCE(SUM(impressions), 0) as total_impressions, '.
                    'COALESCE(SUM(reactions + shares + comments + clicks), 0) as total_engagement, '.
                    'AVG(CASE WHEN reach > 0 THEN (reactions + shares + comments + clicks) * 1.0 / reach ELSE NULL END) as average_engagement_rate'
                )
                ->first();

            $topRows = PostMetric::query()
                ->where('is_available', true)
                ->selectRaw(
                    'post_id, '.
                    'SUM(impressions) as impressions, '.
                    'SUM(reach) as reach, '.
                    'SUM(clicks) as clicks, '.
                    'SUM(reactions) as reactions, '.
                    'SUM(shares) as shares, '.
                    'SUM(comments) as comments, '.
                    'SUM(reactions + shares + comments + clicks) as engagement'
                )
                ->groupBy('post_id')
                ->orderByDesc('engagement')
                ->limit(5)
                ->get();

            $statuses = Post::query()->whereIn('id', $topRows->pluck('post_id'))->pluck('status', 'id');

            $topPosts = $topRows
                ->map(fn ($row): array => [
                    'post_id' => (string) $row->post_id,
                    'impressions' => (int) $row->impressions,
                    'reach' => (int) $row->reach,
                    'clicks' => (int) $row->clicks,
                    'reactions' => (int) $row->reactions,
                    'shares' => (int) $row->shares,
                    'comments' => (int) $row->comments,
                    // Every row here already passed the is_available = true
                    // filter above — no post can appear in $topRows without
                    // at least one available metric row.
                    'available' => true,
                    'status' => $statuses->get($row->post_id, 'draft'),
                ])
                ->values();

            return [
                'top_posts' => $topPosts,
                'total_reach' => (int) ($totals->total_reach ?? 0),
                'total_engagement' => (int) ($totals->total_engagement ?? 0),
                'total_impressions' => (int) ($totals->total_impressions ?? 0),
                'average_engagement_rate' => round((float) ($totals->average_engagement_rate ?? 0.0), 4),
                'best_platform' => $this->computeBestPlatform(),
                'best_publish_hour' => $this->computeBestPublishHour(),
            ];
        });

        return response()->json($payload);
    }

    public function show(Request $request, Post $post, DashboardCacheService $cache): JsonResponse
    {
        $this->authorizeAnalytics($request);

        $data = $cache->rememberPostAnalytics((int) $post->id, function () use ($post): array {
            // No is_available filter here, matching the previous behavior:
            // every row (available or not) contributes to the totals; only
            // the 'available' flag itself distinguishes whether any of them
            // are real provider-reported numbers.
            $totals = PostMetric::query()
                ->where('post_id', $post->id)
                ->selectRaw(
                    'COALESCE(SUM(impressions), 0) as impressions, '.
                    'COALESCE(SUM(reach), 0) as reach, '.
                    'COALESCE(SUM(clicks), 0) as clicks, '.
                    'COALESCE(SUM(reactions), 0) as reactions, '.
                    'COALESCE(SUM(shares), 0) as shares, '.
                    'COALESCE(SUM(comments), 0) as comments, '.
                    'MAX(is_available) as any_available'
                )
                ->first();

            return [
                'post_id' => (string) $post->id,
                'impressions' => (int) $totals->impressions,
                'reach' => (int) $totals->reach,
                'clicks' => (int) $totals->clicks,
                'reactions' => (int) $totals->reactions,
                'shares' => (int) $totals->shares,
                'comments' => (int) $totals->comments,
                'available' => (bool) ($totals->any_available ?? false),
                'status' => $post->status,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Deliberately not cached — see DashboardCacheService::rememberPostAnalytics()'s
     * docblock for why an arbitrary caller-supplied post_ids set is a poor
     * fit for this app's database-backed cache store.
     */
    public function bulk(Request $request): JsonResponse
    {
        $this->authorizeAnalytics($request);

        $validated = $request->validate([
            'post_ids' => ['required', 'array'],
            'post_ids.*' => ['integer'],
        ]);

        $postIds = $validated['post_ids'];
        $statuses = Post::query()->whereIn('id', $postIds)->pluck('status', 'id');

        // Same no-is_available-filter shape as show() above.
        $aggregatesByPost = PostMetric::query()
            ->whereIn('post_id', $postIds)
            ->selectRaw(
                'post_id, '.
                'COALESCE(SUM(impressions), 0) as impressions, '.
                'COALESCE(SUM(reach), 0) as reach, '.
                'COALESCE(SUM(clicks), 0) as clicks, '.
                'COALESCE(SUM(reactions), 0) as reactions, '.
                'COALESCE(SUM(shares), 0) as shares, '.
                'COALESCE(SUM(comments), 0) as comments, '.
                'MAX(is_available) as any_available'
            )
            ->groupBy('post_id')
            ->get()
            ->keyBy('post_id');

        $data = collect($postIds)->map(function ($postId) use ($aggregatesByPost, $statuses): array {
            $row = $aggregatesByPost->get($postId);

            return [
                'post_id' => (string) $postId,
                'impressions' => (int) ($row->impressions ?? 0),
                'reach' => (int) ($row->reach ?? 0),
                'clicks' => (int) ($row->clicks ?? 0),
                'reactions' => (int) ($row->reactions ?? 0),
                'shares' => (int) ($row->shares ?? 0),
                'comments' => (int) ($row->comments ?? 0),
                'available' => (bool) ($row->any_available ?? false),
                'status' => $statuses->get($postId, 'draft'),
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    private function computeBestPlatform(): ?string
    {
        $row = PostMetric::query()
            ->where('is_available', true)
            ->selectRaw(
                'provider, '.
                'AVG(CASE WHEN reach > 0 THEN (reactions + shares + comments + clicks) * 1.0 / reach ELSE 0 END) as rate, '.
                'COUNT(*) as sample_size'
            )
            ->groupBy('provider')
            ->having('sample_size', '>=', self::MIN_SAMPLE_SIZE)
            ->orderByDesc('rate')
            ->first();

        return $row?->provider;
    }

    /**
     * Joins attempts to their matching available metric row in SQL instead
     * of loading both tables and matching post_id/social_page_id pairs in
     * PHP. HOUR()/strftime() are not portable across drivers — this app's
     * tests run on sqlite while production runs MySQL (see e.g.
     * MySqlPublishingConcurrencyTest for the same real-driver split) — so
     * the hour expression is chosen per connection. app.timezone is UTC
     * with no per-environment override, so this reads the same wall-clock
     * hour as Carbon's ->hour did on the same stored value.
     */
    private function computeBestPublishHour(): ?int
    {
        $hourExpression = match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%H', post_publication_attempts.processed_at) AS INTEGER)",
            default => 'HOUR(post_publication_attempts.processed_at)',
        };

        $row = PostPublicationAttempt::query()
            // post_metrics is joined by raw table name, not PostMetric::query()
            // — Eloquent's OrganizationScope global scope only ever applies to
            // the base query's own model, never to a plain SQL-joined table.
            // post_id/social_page_id already can't cross organizations by
            // construction (an attempt's post and page belong to the same
            // org the attempt itself does), so this couldn't leak in
            // practice — the explicit organization_id match here is
            // defense-in-depth, matching this codebase's existing convention
            // of never relying on implicit scoping alone across a raw join.
            ->join('post_metrics', function ($join): void {
                $join->on('post_metrics.post_id', '=', 'post_publication_attempts.post_id')
                    ->on('post_metrics.social_page_id', '=', 'post_publication_attempts.social_page_id')
                    ->on('post_metrics.organization_id', '=', 'post_publication_attempts.organization_id')
                    ->where('post_metrics.is_available', true);
            })
            ->where('post_publication_attempts.status', 'success')
            ->whereNotNull('post_publication_attempts.processed_at')
            ->whereNotNull('post_publication_attempts.social_page_id')
            ->selectRaw(
                "{$hourExpression} as hour, ".
                'AVG(post_metrics.reactions + post_metrics.shares + post_metrics.comments + post_metrics.clicks) as average_engagement, '.
                'COUNT(*) as sample_size'
            )
            ->groupBy('hour')
            ->having('sample_size', '>=', self::MIN_SAMPLE_SIZE)
            ->orderByDesc('average_engagement')
            ->first();

        // getAttribute(), not ->hour: 'hour' is a raw selectRaw() alias, not
        // a real column on PostPublicationAttempt — magic property access
        // would be flagged by static analysis as an undefined property on
        // the model, even though Eloquent resolves it correctly at runtime
        // from whatever the query actually selected.
        $hour = $row?->getAttribute('hour');

        return $hour !== null ? (int) $hour : null;
    }

    /**
     * Analytics are an organization-wide operational view. The active tenant
     * was resolved from a verified membership before this controller runs;
     * never substitute the user's persisted/default organization here.
     */
    private function authorizeAnalytics(Request $request): void
    {
        $user = $request->user();
        $organizationId = app(TenantContext::class)->get();

        if (! $user instanceof User || ! $user->hasOrganizationPermission($organizationId, OrganizationPermission::AnalyticsView)) {
            abort(403, 'You do not have permission to view analytics in this organization.');
        }
    }
}
