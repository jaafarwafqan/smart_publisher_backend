<?php

use App\Support\Observability\SentryEventScrubber;

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),
    'release' => env('SENTRY_RELEASE'),
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),
    'send_default_pii' => false,

    // Redacts access_token/refresh_token/webhook_secret/two_factor_secret
    // (and similarly-named keys) out of request/extra/context data before an
    // event leaves the process. send_default_pii=false above doesn't cover
    // this — it only stops Sentry's own automatic IP/cookie collection, not
    // application data our own code attaches. A class-string callable (not a
    // closure or instance) so this file still works after `config:cache`.
    'before_send' => [SentryEventScrubber::class, 'scrub'],
];
