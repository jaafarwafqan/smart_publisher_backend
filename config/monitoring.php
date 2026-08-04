<?php

return [
    'health' => [
        'enabled' => true,
        'path' => '/up',
    ],
    'sentry' => [
        'dsn' => env('SENTRY_LARAVEL_DSN'),
        'environment' => env('APP_ENV', 'production'),
        'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.1),
    ],
    'bugsnag' => [
        'api_key' => env('BUGSNAG_API_KEY'),
    ],
    'opentelemetry' => [
        'service_name' => env('OTEL_SERVICE_NAME', 'smart-publisher-backend'),
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
        'headers' => env('OTEL_EXPORTER_OTLP_HEADERS'),
    ],
];
