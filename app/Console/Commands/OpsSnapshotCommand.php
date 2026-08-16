<?php

namespace App\Console\Commands;

use App\Services\ContextLogger;
use App\Support\Ops\OpsAlertNotifier;
use App\Support\Ops\OpsHealthSnapshot;
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
 *
 * Phase 4 (observability, 2026-08-16): added a fourth real signal — open
 * (un-retried) dead_letter_jobs — and a real delivery channel
 * (OpsAlertNotifier) alongside the structured log entries, which remain the
 * source of truth and fire identically whether or not Telegram delivery is
 * configured.
 */
class OpsSnapshotCommand extends Command
{
    protected $signature = 'app:ops-snapshot';

    protected $description = 'Compute real publishing-pipeline health metrics and log/notify an alert when a threshold is breached.';

    public function handle(OpsHealthSnapshot $snapshot, OpsAlertNotifier $notifier): int
    {
        $data = $snapshot->compute();
        $thresholds = $data['thresholds'];
        $queueLength = $data['queue_length'];
        $failureRate = $data['publish_failure_rate'];
        $recentTotal = $data['publish_failure_sample_size'];
        $retryStormCount = $data['retry_storm_count'];
        $deadLetterOpenCount = $data['dead_letter_open_count'];

        $this->line(sprintf(
            'queue_length=%d publish_failure_rate=%s (n=%d) retry_storm_count=%d dead_letter_open_count=%d',
            $queueLength,
            round($failureRate, 4),
            $recentTotal,
            $retryStormCount,
            $deadLetterOpenCount,
        ));

        if ($queueLength >= (int) $thresholds['queue_length']) {
            ContextLogger::warning('ops.alert.queue_length', [
                'value' => $queueLength,
                'threshold' => $thresholds['queue_length'],
            ]);
            $notifier->notify(sprintf(
                '⚠️ Smart Publisher: publish queue length is %d (threshold %d).',
                $queueLength,
                $thresholds['queue_length'],
            ));
        }

        if ($recentTotal > 0 && $failureRate >= (float) $thresholds['publish_failure_rate']) {
            ContextLogger::error('ops.alert.publish_failure_rate', [
                'value' => $failureRate,
                'threshold' => $thresholds['publish_failure_rate'],
                'sample_size' => $recentTotal,
            ]);
            $notifier->notify(sprintf(
                '🔴 Smart Publisher: publish failure rate is %s%% over the last %d attempts (threshold %s%%).',
                round($failureRate * 100, 1),
                $recentTotal,
                round((float) $thresholds['publish_failure_rate'] * 100, 1),
            ));
        }

        if ($retryStormCount >= (int) $thresholds['retry_storm_count']) {
            ContextLogger::error('ops.alert.retry_storm', [
                'value' => $retryStormCount,
                'threshold' => $thresholds['retry_storm_count'],
            ]);
            $notifier->notify(sprintf(
                '🔴 Smart Publisher: %d attempts are stuck in a retry storm (threshold %d).',
                $retryStormCount,
                $thresholds['retry_storm_count'],
            ));
        }

        if ($deadLetterOpenCount >= (int) $thresholds['dead_letter_open_count']) {
            ContextLogger::error('ops.alert.dead_letter_open_count', [
                'value' => $deadLetterOpenCount,
                'threshold' => $thresholds['dead_letter_open_count'],
            ]);
            $notifier->notify(sprintf(
                '🔴 Smart Publisher: %d unresolved dead-letter jobs (threshold %d). Review /publishing/dead-letters.',
                $deadLetterOpenCount,
                $thresholds['dead_letter_open_count'],
            ));
        }

        return self::SUCCESS;
    }
}
