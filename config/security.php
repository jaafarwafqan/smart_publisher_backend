<?php

return [
    // Local HTTP development remains possible, but staging/production must
    // explicitly opt into this. AppServiceProvider refuses unsafe deployment
    // configuration before the application starts serving requests.
    'require_https' => env('SECURITY_REQUIRE_HTTPS', false),

    'hsts' => [
        'enabled' => env('SECURITY_HSTS_ENABLED', true),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => env('SECURITY_HSTS_PRELOAD', false),
    ],
];
