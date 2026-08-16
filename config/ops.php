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
        // Phase 4 (observability, 2026-08-16): unresolved dead-letter jobs
        // (retried_at IS NULL) — an absolute open-count threshold, same
        // shape as queue_length/retry_storm_count above, not a delta. A
        // dead letter that has already been retried (retried_at set) no
        // longer counts toward this, whether that retry ultimately
        // succeeded or not — a fresh failure of its own would create a new
        // row.
        'dead_letter_open_count' => (int) env('OPS_ALERT_DEAD_LETTER_OPEN_COUNT', 20),
    ],

    // The window used to compute publish_failure_rate — only recently
    // resolved attempts count, so a quiet backlog from hours ago doesn't
    // skew today's rate.
    'failure_rate_window_minutes' => (int) env('OPS_FAILURE_RATE_WINDOW_MINUTES', 60),

    // Phase 4 (observability, 2026-08-16): admin channel for
    // app:ops-snapshot's real threshold-breach alerts. Deliberately
    // fail-safe/opt-in — both must be set for anything to be sent; leaving
    // either unset means alerting stays log-only (ContextLogger, already
    // real and already the source of truth) rather than blocking or
    // erroring. Not a new provider integration: this is the same Telegram
    // Bot API sendMessage call TelegramProvider already makes for real
    // publishing, made here against a fixed admin chat instead of a
    // per-organization one.
    'telegram_alert' => [
        'bot_token' => env('OPS_ALERT_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('OPS_ALERT_TELEGRAM_CHAT_ID'),
    ],
];
