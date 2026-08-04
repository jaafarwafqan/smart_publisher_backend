<?php

return [
    /**
     * CTO audit Sprint 6 (Production Ops): thresholds for
     * app:ops-snapshot, which computes REAL operational metrics from the
     * publishing pipeline's own data (PostPublicationAttempt rows) rather
     * than a metrics facade with nothing behind it. These match the values
     * already documented in docs/operations/incident_runbook.md so the
     * runbook and the actual running check agree.
     */
    'alert_thresholds' => [
        'queue_length' => (int) env('OPS_ALERT_QUEUE_LENGTH', 200),
        'publish_failure_rate' => (float) env('OPS_ALERT_PUBLISH_FAILURE_RATE', 0.05),
        'retry_storm_count' => (int) env('OPS_ALERT_RETRY_STORM_COUNT', 50),
    ],

    // The window used to compute publish_failure_rate — only recently
    // resolved attempts count, so a quiet backlog from hours ago doesn't
    // skew today's rate.
    'failure_rate_window_minutes' => (int) env('OPS_FAILURE_RATE_WINDOW_MINUTES', 60),
];
