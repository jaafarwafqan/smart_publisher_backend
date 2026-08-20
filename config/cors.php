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

    // Browser sessions use httpOnly cookies for Flutter Web. Wildcard
    // origins are deliberately forbidden whenever credentials are enabled;
    // CORS_ALLOWED_ORIGINS must name each trusted frontend explicitly.
    'supports_credentials' => env('CORS_SUPPORTS_CREDENTIALS', env('AUTH_WEB_COOKIE_ENABLED', false)),
];
