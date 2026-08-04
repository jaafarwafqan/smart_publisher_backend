<?php

// CORS_ALLOWED_ORIGINS is a comma-separated list. Falls back to the local
// dev defaults (including wildcard localhost ports) when unset, so this
// stays a no-op change for local development, but a real production
// deployment can now restrict it via one env var instead of editing code.
$defaultOrigins = 'http://localhost:65194,http://127.0.0.1:65194,http://localhost:*,http://127.0.0.1:*';

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', $defaultOrigins))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
