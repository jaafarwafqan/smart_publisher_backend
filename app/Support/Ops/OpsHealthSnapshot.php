<?php

namespace App\Support\Ops;

use App\Models\DeadLetterJob;
use App\Models\PostPublicationAttempt;
use App\Models\Scopes\OrganizationScope;

/**
 * Phase 4 (observability, 2026-08-16): the real metric computation shared by
 * `app:ops-snapshot` (OpsSnapshotCommand — logs/notifies on a threshold
 * breach every 5 minutes via the scheduler) and `GET /admin/ops`
 * (AdminOpsController — an on-demand read for a human operator). Both read
 * the exact same real data (PostPublicationAttempt/DeadLetterJob rows), so
 * the computation lives in one place rather than two copies that could
 * silently drift apart.
 */
class OpsHealthSnapshot
{
    /**
     * @return array{
     *     queue_length: int,
     *     publish_failure_rate: float,
     *     publish_failure_sample_size: int,
     *     retry_storm_count: int,
     *     dead_letter_open_count: int,
     *     thresholds: array<string, int|float>,
     *     window_minutes: int,
     * }
     */
    public function compute(): array
    {
        $thresholds = (array) config('ops.alert_thresholds');
        $windowMinutes = (int) config('ops.failure_rate_window_minutes', 60);
        $windowStart = now()->subMinutes($windowMinutes);

        // System-level cross-tenant scan — operational health is inherently
        // a platform-wide view, not scoped to one organization. Same
        // sanctioned pattern as ProcessScheduledPostsJob's own due-post
        // scan; see OrganizationScope's docblock.
        $queueLength = PostPublicationAttempt::withoutGlobalScope(OrganizationScope::class)
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        $recentTotal = PostPublicationAttempt::withoutGlobalScope(OrganizationScope::class)
            ->where('updated_at', '>=', $windowStart)
            ->count();

        $recentFailed = PostPublicationAttempt::withoutGlobalScope(OrganizationScope::class)
            ->whereIn('status', ['failed', 'dead_letter'])
            ->where('updated_at', '>=', $windowStart)
            ->count();

        $retryStormCount = PostPublicationAttempt::withoutGlobalScope(OrganizationScope::class)
            ->where('status', 'retry_scheduled')
            ->count();

        $deadLetterOpenCount = DeadLetterJob::withoutGlobalScope(OrganizationScope::class)
            ->whereNull('retried_at')
            ->count();

        return [
            'queue_length' => $queueLength,
            'publish_failure_rate' => $recentTotal > 0 ? $recentFailed / $recentTotal : 0.0,
            'publish_failure_sample_size' => $recentTotal,
            'retry_storm_count' => $retryStormCount,
            'dead_letter_open_count' => $deadLetterOpenCount,
            'thresholds' => $thresholds,
            'window_minutes' => $windowMinutes,
        ];
    }
}
