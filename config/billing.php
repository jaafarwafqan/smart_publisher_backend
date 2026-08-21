<?php

return [
    /*
    | 2026-08-21: neither FIB, ZainCash, nor Qi Card support recurring
    | subscriptions — every Iraqi gateway integrated here is one-time-payment
    | only, so PaymentGatewayContract is shaped around a "pay for N months,
    | extend current_period_end" model rather than Stripe's recurring one
    | (see PaymentGatewayContract's own docblock). This key selects which
    | implementation the container binds the contract to — see
    | AppServiceProvider::register(). Stripe stays available as a documented
    | future option (e.g. for a UAE/international entity) rather than being
    | deleted; it is simply not the default anymore.
    */
    'gateway' => env('BILLING_GATEWAY', 'stripe'),

    /*
    | Stripe is intentionally configured entirely through the deployment
    | secret store. A blank key disables checkout rather than offering a
    | misleading payment button. Each paid plan stores its Stripe Price ID in
    | plans.stripe_price_id; pricing itself remains a business decision, not
    | fabricated application data.
    */
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_base_url' => env('STRIPE_API_BASE_URL', 'https://api.stripe.com'),
        'webhook_tolerance_seconds' => (int) env('STRIPE_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],

    /*
    | FIB (First Iraqi Bank) — integrated via the official
    | first-iraqi-bank/fib-laravel-payment-sdk package; its own config lives
    | in config/fib.php (published from the package, then edited to read
    | FIB_API_KEY/FIB_API_SECRET — see that file's own comment). Nothing
    | here duplicates those values; this block only holds what
    | FibBillingGateway needs that the SDK's config doesn't already cover.
    */
    'fib' => [
        'currency' => env('FIB_CURRENCY', 'IQD'),
    ],

    /*
    | ZainCash — no official Laravel SDK, integrated by hand against its
    | documented OAuth2 + JWT-callback flow. Test environment base URL is
    | https://pg-api-uat.zaincash.iq; production uses the real ZainCash
    | gateway host. MSISDN is the merchant's own phone number, not a
    | customer's. IQD only — ZainCash has no other supported currency for
    | this integration.
    */
    'zaincash' => [
        'base_url' => env('ZAINCASH_BASE_URL', 'https://pg-api-uat.zaincash.iq'),
        'client_id' => env('ZAINCASH_CLIENT_ID'),
        'client_secret' => env('ZAINCASH_CLIENT_SECRET'),
        'msisdn' => env('ZAINCASH_MSISDN'),
        'success_url' => env('ZAINCASH_SUCCESS_URL'),
        'failure_url' => env('ZAINCASH_FAILURE_URL'),
    ],

    'return_url' => env('BILLING_RETURN_URL', rtrim((string) env('FRONTEND_URL', ''), '/').'/billing'),
];
