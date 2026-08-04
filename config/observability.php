<?php

return [
    'sentry_dsn' => env('SENTRY_LARAVEL_DSN'),
    'bugsnag_api_key' => env('BUGSNAG_API_KEY'),
    'otel_endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
    'otel_service_name' => env('OTEL_SERVICE_NAME', 'smart-publisher-backend'),
];
