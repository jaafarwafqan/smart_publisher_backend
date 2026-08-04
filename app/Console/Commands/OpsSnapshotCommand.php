<?php

namespace App\Console\Commands;

use App\Models\PostPublicationAttempt;
use App\Models\Scopes\OrganizationScope;
use App\Services\ContextLogger;
use Illuminate\Console\Command;

/**
 * CTO audit Sprint 6 (Production Ops — observability/alerting). This is a
 * deliberately narrow scope: docs/operations/incident_runbook.md already
 * documented alert thresholds (queue length, publish failure rate, retry
 * storm) as a "Reference implementation" pointing at Flutter's
 * MonitoringAlertPolicy — but that class has zero callers, and 4 of its 5
 * metrics are never written to anywhere in the app. Rather than leaving
 * that misleading (a runbook implying a live check that doesn't run), this
 * computes 3 of those same signals from data that already genuinely
 * exists — PostPublicationAttempt rows written by the Sprint 3 publishing
 * engine — and logs a real, grep-able alert when a threshold is breached.
 *
 * Crash rate and API latency are NOT covered here: crash rate needs a real
 * crash-reporting sink (Sentry/Crashlytics), which is an external-vendor
 * decision explicitly deferred; API latency is a Flutter client-side signal
 * with a different data source. Both stay open, not silently claimed done.
 */
class OpsSnapshotCommand extends Command
{
    protected $signature = 'app:ops-snapshot';

    protected $description = 'Compute real publishing-pipeline health metrics and log an alert when a threshold is breached.';

    public function handle(): int
    {
        $thresholds = (array) config('ops.alert_thresholds');
        $windowStart = now()->subMinutes((int) config('ops.failure_rate_window_minutes', 60));

        // System-level cross-tenant scan (same sanctioned pattern as
        // ProcessScheduledPostsJob/SyncPostMetricsCommand) — operational
        // health is inherently a platform-wide view, not scoped to one
        // organization.
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

        $failureRate = $recentTotal > 0 ? $recentFailed / $recentTotal : 0.0;

        $retryStormCount = PostPublicationAttempt::withoutGlobalScope(OrganizationScope::class)
            ->where('status', 'retry_scheduled')
            ->count();

        $this->line(sprintf(
            'queue_length=%d publish_failure_rate=%s (n=%d) retry_storm_count=%d',
            $queueLength,
            round($failureRate, 4),
            $recentTotal,
            $retryStormCount,
        ));

        if ($queueLength >= (int) $thresholds['queue_length']) {
            ContextLogger::warning('ops.alert.queue_length', [
                'value' => $queueLength,
                'threshold' => $thresholds['queue_length'],
            ]);
        }

        if ($recentTotal > 0 && $failureRate >= (float) $thresholds['publish_failure_rate']) {
            ContextLogger::error('ops.alert.publish_failure_rate', [
                'value' => $failureRate,
                'threshold' => $thresholds['publish_failure_rate'],
                'sample_size' => $recentTotal,
            ]);
        }

        if ($retryStormCount >= (int) $thresholds['retry_storm_count']) {
            ContextLogger::error('ops.alert.retry_storm', [
                'value' => $retryStormCount,
                'threshold' => $thresholds['retry_storm_count'],
            ]);
        }

        return self::SUCCESS;
    }
}
