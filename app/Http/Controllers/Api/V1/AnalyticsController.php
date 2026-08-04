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
use Illuminate\Support\Collection;

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

    public function dashboard(Request $request): JsonResponse
    {
        $this->authorizeAnalytics($request);

        $available = PostMetric::query()->where('is_available', true)->get();

        $totalReach = (int) $available->sum('reach');
        $totalImpressions = (int) $available->sum('impressions');
        $totalEngagement = (int) $available->sum(fn (PostMetric $m): int => $m->engagement);

        $rates = $available
            ->filter(fn (PostMetric $m): bool => $m->reach > 0)
            ->map(fn (PostMetric $m): float => $m->engagement / $m->reach);
        $averageEngagementRate = $rates->isEmpty() ? 0.0 : round((float) $rates->avg(), 4);

        $topPostIds = $available
            ->groupBy('post_id')
            ->map(fn (Collection $rows): int => $rows->sum(fn (PostMetric $m): int => $m->engagement))
            ->sortDesc()
            ->take(5)
            ->keys();

        $statuses = Post::query()->whereIn('id', $topPostIds)->pluck('status', 'id');

        $topPosts = $topPostIds
            ->map(fn ($postId) => $this->formatMetricsSummary(
                (string) $postId,
                $available->where('post_id', $postId),
                $statuses->get($postId, 'draft')
            ))
            ->values();

        return response()->json([
            'top_posts' => $topPosts,
            'total_reach' => $totalReach,
            'total_engagement' => $totalEngagement,
            'total_impressions' => $totalImpressions,
            'average_engagement_rate' => $averageEngagementRate,
            'best_platform' => $this->computeBestPlatform($available),
            'best_publish_hour' => $this->computeBestPublishHour(),
        ]);
    }

    public function show(Request $request, Post $post): JsonResponse
    {
        $this->authorizeAnalytics($request);

        $rows = PostMetric::query()->where('post_id', $post->id)->get();

        return response()->json([
            'data' => $this->formatMetricsSummary((string) $post->id, $rows, $post->status),
        ]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $this->authorizeAnalytics($request);

        $validated = $request->validate([
            'post_ids' => ['required', 'array'],
            'post_ids.*' => ['integer'],
        ]);

        $postIds = $validated['post_ids'];
        $statuses = Post::query()->whereIn('id', $postIds)->pluck('status', 'id');
        $rowsByPost = PostMetric::query()->whereIn('post_id', $postIds)->get()->groupBy('post_id');

        $data = collect($postIds)->map(fn ($postId) => $this->formatMetricsSummary(
            (string) $postId,
            $rowsByPost->get($postId, collect()),
            $statuses->get($postId, 'draft')
        ));

        return response()->json(['data' => $data->values()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMetricsSummary(string $postId, Collection $rows, string $status): array
    {
        return [
            'post_id' => $postId,
            'impressions' => (int) $rows->sum('impressions'),
            'reach' => (int) $rows->sum('reach'),
            'clicks' => (int) $rows->sum('clicks'),
            'reactions' => (int) $rows->sum('reactions'),
            'shares' => (int) $rows->sum('shares'),
            'comments' => (int) $rows->sum('comments'),
            'available' => $rows->contains('is_available', true),
            'status' => $status,
        ];
    }

    private function computeBestPlatform(Collection $availableMetrics): ?string
    {
        $best = null;
        $bestRate = -1.0;

        foreach ($availableMetrics->groupBy('provider') as $provider => $rows) {
            if ($rows->count() < self::MIN_SAMPLE_SIZE) {
                continue;
            }

            $rate = (float) $rows->avg(fn (PostMetric $m): float => $m->reach > 0 ? $m->engagement / $m->reach : 0.0);

            if ($rate > $bestRate) {
                $bestRate = $rate;
                $best = (string) $provider;
            }
        }

        return $best;
    }

    private function computeBestPublishHour(): ?int
    {
        $attempts = PostPublicationAttempt::query()
            ->where('status', 'success')
            ->whereNotNull('processed_at')
            ->whereNotNull('social_page_id')
            ->get(['post_id', 'social_page_id', 'processed_at']);

        if ($attempts->isEmpty()) {
            return null;
        }

        $metricsByKey = PostMetric::query()
            ->where('is_available', true)
            ->get()
            ->keyBy(fn (PostMetric $m): string => $m->post_id.':'.$m->social_page_id);

        $best = null;
        $bestAverage = -1.0;

        foreach ($attempts->groupBy(function (PostPublicationAttempt $attempt): int {
            $processedAt = $attempt->processed_at;

            if ($processedAt === null) {
                throw new \LogicException('A successful publication attempt must have a processing time.');
            }

            return $processedAt->hour;
        }) as $hour => $group) {
            $engagements = $group
                ->map(fn (PostPublicationAttempt $a) => $metricsByKey->get($a->post_id.':'.$a->social_page_id)?->engagement)
                ->filter(fn ($value): bool => $value !== null);

            if ($engagements->count() < self::MIN_SAMPLE_SIZE) {
                continue;
            }

            $average = (float) $engagements->avg();

            if ($average > $bestAverage) {
                $bestAverage = $average;
                $best = (int) $hour;
            }
        }

        return $best;
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
