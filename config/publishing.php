<?php

return [
    'queue' => env('PUBLISH_QUEUE_NAME', 'publishing'),
    'max_retries' => (int) env('PUBLISH_MAX_RETRIES', 3),

    // An "unknown" classified error (not recognized as clearly retryable or
    // clearly permanent) gets fewer attempts than a confirmed-retryable one
    // — cautious middle ground between "assume safe to hammer" and "give up
    // instantly."
    'unknown_error_max_retries' => (int) env('PUBLISH_UNKNOWN_MAX_RETRIES', 1),

    // Provider-wide circuit: trips on genuine platform-side trouble
    // (widespread 5xx/timeouts). Deliberately higher than the org-scoped
    // threshold so one tenant's bad token can't trip it on its own.
    'circuit_breaker_threshold' => (int) env('PUBLISH_CB_THRESHOLD', 5),
    'circuit_breaker_ttl_minutes' => (int) env('PUBLISH_CB_TTL_MINUTES', 10),

    // Organization-scoped circuit: trips fast on a single tenant's local
    // failures (revoked token, account-specific rate limit) without
    // affecting any other organization's publishing on the same provider.
    'org_circuit_breaker_threshold' => (int) env('PUBLISH_ORG_CB_THRESHOLD', 3),
    'org_circuit_breaker_ttl_minutes' => (int) env('PUBLISH_ORG_CB_TTL_MINUTES', 10),

    'idempotency_ttl_minutes' => (int) env('PUBLISH_IDEMPOTENCY_TTL_MINUTES', 60),

    // A 'processing' attempt whose claim is older than this is assumed to
    // belong to a worker that crashed mid-publish rather than one still
    // legitimately in flight — see AttemptStateMachine::reclaimStale().
    'claim_stale_after_seconds' => (int) env('PUBLISH_CLAIM_STALE_SECONDS', 300),

    'backoff_base_seconds' => (int) env('PUBLISH_BACKOFF_BASE_SECONDS', 10),
    'backoff_max_seconds' => (int) env('PUBLISH_BACKOFF_MAX_SECONDS', 900),

    // Post.status transitions — was defined but never actually enforced
    // anywhere (confirmed via a project-wide grep); also didn't even
    // include 'publishing' as a valid state despite the app using it since
    // the PRA-004 fix. Fixed and wired into PostStateMachine, kept
    // deliberately permissive (drafts/scheduled/publishing can all reset
    // back to draft via markDraft, matching existing behavior) rather than
    // guessing at new restrictions nobody asked for.
    // Sprint 1 (Publishing Recovery): added 'partial_success' — a batch
    // whose targets settled with a mix of success and failure was
    // previously indistinguishable from a total failure (PublicationBatchCoordinator
    // only ever emitted 'published' or 'failed'), hiding the fact that some
    // platforms really did receive the post. Added 'cancelled' — cancelling
    // a still-scheduled or not-yet-claimed publishing post had no
    // supported status to land in at all; PostController::cancel() is the
    // only caller, and only ever cancels a 'publishing' post when every one
    // of its batch's attempts is still 'pending' (nothing claimed/in-flight
    // — see doCancel()'s row-locked check).
    'allowed_status_transitions' => [
        'draft' => ['draft', 'scheduled', 'publishing'],
        'scheduled' => ['scheduled', 'publishing', 'failed', 'draft', 'cancelled'],
        'publishing' => ['publishing', 'published', 'failed', 'partial_success', 'draft', 'cancelled'],
        'published' => ['published', 'draft'],
        'failed' => ['draft', 'scheduled', 'publishing', 'failed'],
        'partial_success' => ['partial_success', 'draft'],
        'cancelled' => ['cancelled', 'draft'],
    ],
];
